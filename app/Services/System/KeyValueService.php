<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\System;

class KeyValueService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \Framework\Core\Container */
    protected $container;
    /** @var \App\Models\KeyValueModel */
    private $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;

    public function __construct(
        \Framework\Core\Container $container,
        \Framework\Cache\Interfaces\CacheInterface $cache
    ) {
        $this->container = $container;
        $this->cache = $cache;
    }

    private function getModel()
    {
        if ($this->dbModel !== null) {
            return $this->dbModel;
        }
        $resolved = $this->container->get(\App\Models\KeyValueModel::class);
        // 首次获取可能让出协程；保留已经建立的模型引用。
        return $this->dbModel = $this->dbModel ?? $resolved;
    }

    private function normalizeKey(string $key): string
    {
        return strlen($key) > 32 ? md5($key) : $key;
    }

    /**
     * @return array
     */
    public function get(string $key)
    {
        $key = $this->normalizeKey($key);
        $static = $this->getState('static', []);
        if (!isset($static[$key])) {
            $result = $this->getModel()->read(['key' => $key]);
            if (empty($result)) return null;
            $static[$key] = json_decode($result['value'], true);
            $this->setState('static', $static);
        }
        return $static[$key];
    }

    public function set(string $key, $value): bool
    {
        $key = $this->normalizeKey($key);

        $lockKey = 'lock:kv:set:' . $key;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            $static = $this->getState('static', []);
            $static[$key] = $value;
            $this->setState('static', $static);

            $data = [
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ];

            $result = $this->getModel()->read(['key' => $key]);

            if (empty($result)) {
                return $this->getModel()->insert($data) > 0;
            } else {
                unset($data['key']);
                return $this->getModel()->update(['key' => $key], $data) > 0;
            }
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function delete(string $key): int
    {
        $key = $this->normalizeKey($key);
        $static = $this->getState('static', []);
        unset($static[$key]);
        $this->setState('static', $static);
        return $this->getModel()->delete(['key' => $key]);
    }

    // ---- kv + cache ----

    public function cacheGet(string $key)
    {
        $key = $this->normalizeKey($key);
        // 使用 cacheWithLock 防止缓存击穿 (Cache Stampede)
        return $this->cache->cacheWithLock(
            $key,
            'lock:kv:' . $key,
            function () use ($key) {
                return $this->get($key);
            }
        );
    }

    public function cacheSet(string $key, $value, int $expire = 0): bool
    {
        $ok1 = $this->cache->set($key, $value, $expire);
        $ok2 = $this->set($key, $value);
        return $ok1 && $ok2;
    }

    public function cacheDelete(string $key): bool
    {
        $ok1 = $this->cache->delete($key);
        $ok2 = $this->delete($key);
        return $ok1 && $ok2;
    }

    // ---- setting ----

    /**
     * @return void
     */
    public function settingGet(string $key)
    {
        $setting = $this->getState('setting');
        if (null === $setting) {
            $setting = $this->cacheGet('setting') ?? [];
            $this->setState('setting', $setting);
        }
        return $setting[$key] ?? null;
    }

    public function settingSet(string $key, $value): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            $setting = $this->cacheGet('setting') ?? [];
            $setting[$key] = $value;
            $this->setState('setting', $setting);
            return $this->cacheSet('setting', $setting);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * 在全局 setting 锁内原子合并指定配置域。
     */
    public function settingMerge(string $key, array $changes): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            $setting = $this->cacheGet('setting') ?? [];
            $current = isset($setting[$key]) && is_array($setting[$key]) ? $setting[$key] : [];
            $setting[$key] = array_merge($current, $changes);
            $this->setState('setting', $setting);
            return $this->cacheSet('setting', $setting);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    public function settingDelete(string $key): bool
    {
        $lockKey = 'lock:setting';
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return false;

        try {
            $setting = $this->cacheGet('setting') ?? [];
            if (!isset($setting[$key])) return true;

            unset($setting[$key]);
            $this->setState('setting', $setting);
            return $this->cacheSet('setting', $setting);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }
}
