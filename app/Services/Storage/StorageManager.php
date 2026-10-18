<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

class StorageManager
{
    /** @var array 存储配置 */
    protected $config;
    /** @var array 应用配置 */
    protected $appConfig;

    public function __construct(array $uploadConfig, array $appConfig)
    {
        $this->config = $uploadConfig;
        $this->appConfig = $appConfig;
    }

    /**
     * 获取视图路径 (Assets/Themes)
     * @return string
     */
    public function getViewPath(): string
    {
        $urlRewriteOn = $this->appConfig['url_rewrite_on'] ?? 0;
        $path = $this->appConfig['path'] ?? './';
        $viewUrl = $this->appConfig['view_url'] ?? '/views/';

        // 判断是否开启了 URL 重写功能
        return $urlRewriteOn > 1 ? $path . trim($viewUrl, './') . '/' : $viewUrl;
    }

    /**
     * 加锁驱动实例 (便捷方法)
     */
    public function disk(?string $name = null): \App\Services\Storage\Interfaces\StorageInterface
    {
        $name = strtolower($name ?: $this->config['default'] ?? 'local');

        $cfg = $this->config['disks'][$name] ?? [];

        // hook app_Http_Storage_StorageManager_disk_start.php

        switch ($name) {
            case 'local':
                return new \App\Services\Storage\Drivers\LocalStorage($cfg);
                /* case 'aws':
                return new \App\Services\Storage\Drivers\S3Storage($cfg); */
                // hook app_Http_Storage_StorageManager_disk_before.php
            default:
                return new \App\Services\Storage\Drivers\LocalStorage($cfg);
        }

        // hook app_Http_Storage_StorageManager_disk_end.php
    }

    /**
     * 路由并生成头像预览 URL (无状态、高性能)
     * @param int $timestamp 头像上传时间戳 (0 = 默认头像)
     * @param int $status 存储状态 (0: 本地 / 1: 云端)
     * @return string
     */
    public function getAvatarUrl(int $timestamp, int $status): string
    {
        if (0 === $timestamp) return '/views/image/avatar.png';

        $localUrl = $this->config['disks']['local']['url'] ?? '/upload/';
        $ym = date('Ym', $timestamp);
        $path = 'avatar/' . $ym . '/' . $timestamp . '.png';

        // 云端状态且当前开启了云存储驱动
        if (1 === $status && ($this->config['default'] ?? 'local') !== 'local'
            && !($this->disk($this->config['default']) instanceof \App\Services\Storage\Drivers\LocalStorage)) {
            $cloudBaseUrl = $this->config['disks'][$this->config['default']]['url'] ?? '';
            return rtrim($cloudBaseUrl, '/') . '/' . ltrim($localUrl, '/') . $path;
        }

        // 默认本地
        return $localUrl . $path;
    }

    /**
     * 获取头像本地物理全路径
     * @param int $timestamp
     * @return string
     */
    public function getAvatarPath(int $timestamp): string
    {
        if (0 === $timestamp) return '';

        $localRoot = $this->config['disks']['local']['root'] ?? '';
        $ym = date('Ym', $timestamp);

        return $localRoot . 'avatar' . DIRECTORY_SEPARATOR . $ym . DIRECTORY_SEPARATOR . $timestamp . '.png';
    }

    /**
     * 删除物理文件 (支持本地和同步删除云端)
     * @param string $relativeKey 相对路径 (相对于 upload 根目录)
     * @param int $status 存储状态 (0: 本地 / 1: 云端)
     * @return bool
     */
    public function deleteFile(string $relativeKey, int $status): bool
    {
        if (empty($relativeKey)) return false;
        if (!in_array($status, [0, 1], true) || ($status === 1 && ($this->config['default'] ?? 'local') === 'local')) return false;

        // 云端与本地删除必须共用同一个已验证的根内相对 key。
        // 不能把 DB path 直接交给 LocalStorage，否则 ../ 会在 root 拼接后跨根。
        $cleanPath = $this->normalizeDeleteKey($relativeKey);
        if ($cleanPath === null) return false;

        $localUrl = trim(str_replace('\\', '/', (string)($this->config['disks']['local']['url'] ?? 'upload/')), '/');
        if ($localUrl !== '') $localUrl .= '/';

        $result = true;

        // 2. 如果在云端 (status=1)，调用默认云驱动删除
        if (1 === $status && ($this->config['default'] ?? 'local') !== 'local') {
            try {
                // 云端 Key 通常需要包含 upload/ 前缀以匹配 UploadToCloudJob 的逻辑
                $cloudKey = ltrim($localUrl, '/\\') . $cleanPath;
                $cloud = $this->disk($this->config['default']);
                if ($cloud instanceof \App\Services\Storage\Drivers\LocalStorage) return false;
                $result = $cloud->delete($cloudKey);
            } catch (\Exception $e) {
                $result = false;
            }
        }

        if (!$result) return false;

        // 3. 云端结果已确认后再清理本地
        try {
            // 本地磁盘驱动的 root 通常已设为 storage/upload/，所以传 cleanPath 即可
            $localResult = $this->disk('local')->delete($cleanPath);
            $result = $result && $localResult;
        } catch (\Exception $e) {
            $result = false;
        }

        return $result;
    }

    /**
     * 将数据库 path、URL 或相对 key 解析为正式存储根内的相对路径。
     *
     * @return string|null null 表示输入不安全或不属于配置存储根
     */
    private function normalizeDeleteKey(string $path): ?string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) return null;

        $path = str_replace('\\', '/', $path);
        $rootConfig = str_replace('\\', '/', (string)($this->config['disks']['local']['root'] ?? ''));
        if ($rootConfig === '') return null;

        $appRoot = rtrim(str_replace('\\', '/', (string)(\defined('APP_PATH') ? \APP_PATH : '')), '/');
        $rootAbsolute = $rootConfig;
        if ($rootAbsolute[0] !== '/') {
            if ($appRoot === '') return null;
            $rootAbsolute = $appRoot . '/' . ltrim($rootAbsolute, '/');
        }
        $rootReal = realpath($rootAbsolute);
        if ($rootReal === false || !is_dir($rootReal)) return null;
        $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');

        // 绝对路径只允许从当前配置根相对化，任何其他绝对路径都拒绝。
        if (isset($path[0]) && $path[0] === '/') {
            $absolute = rtrim($path, '/');
            $rootPrefix = $rootReal . '/';
            if (strpos($absolute . '/', $rootPrefix) !== 0) return null;
            $path = substr($absolute, strlen($rootPrefix));
        } else {
            $path = ltrim($path, '/');
            $prefixes = [];

            $rootRelative = trim($rootConfig, '/');
            if ($rootRelative !== '' && strpos($rootRelative, ':') === false) {
                $prefixes[] = $rootRelative;
            }
            if ($appRoot !== '' && strpos($rootReal . '/', $appRoot . '/') === 0) {
                $prefixes[] = ltrim(substr($rootReal, strlen($appRoot)), '/');
            }
            $localUrl = trim(str_replace('\\', '/', (string)($this->config['disks']['local']['url'] ?? 'upload/')), '/');
            if ($localUrl !== '') $prefixes[] = $localUrl;

            foreach (array_unique(array_filter($prefixes)) as $prefix) {
                $prefix = trim((string)$prefix, '/');
                if ($path === $prefix) {
                    $path = '';
                    break;
                }
                if (strpos($path, $prefix . '/') === 0) {
                    $path = substr($path, strlen($prefix) + 1);
                    break;
                }
            }
        }

        $segments = explode('/', $path);
        if (empty($segments)) return null;
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return null;
            if (preg_match('/[\x00-\x1f\x7f]/', $segment)) return null;
        }
        $cleanPath = implode('/', $segments);

        // 对已存在目标再做 canonical 校验，阻断根内符号链接指向根外。
        $candidate = $rootReal . '/' . $cleanPath;
        if (is_link($candidate)) return null;
        if (file_exists($candidate)) {
            $targetReal = realpath($candidate);
            if ($targetReal === false || !is_file($targetReal)) return null;
            $targetReal = str_replace('\\', '/', $targetReal);
            if (strpos($targetReal . '/', $rootReal . '/') !== 0) return null;
        }

        return $cleanPath;
    }

    /**
     * 删除头像
     */
    public function deleteAvatar(int $timestamp, int $status): bool
    {
        if (0 === $timestamp) return true;
        $ym = date('Ym', $timestamp);
        $relativeKey = 'avatar/' . $ym . '/' . $timestamp . '.png';
        return $this->deleteFile($relativeKey, $status);
    }

    /**
     * 删除合集封面
     */
    public function deleteCollectionCover(string $relativeKey, int $status): bool
    {
        return $this->deleteFile($relativeKey, $status);
    }

    /**
     * 动态代理方法调用到默认驱动 (可选，语法糖)
     * 允许直接调用 $manager->writeStream() 而不用 $manager->disk()->writeStream()
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        return $this->disk()->$method(...$parameters);
    }
}
