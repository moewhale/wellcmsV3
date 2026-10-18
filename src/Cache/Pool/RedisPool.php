<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Cache\Pool;

/**
 * RedisPool：支持协程与 FPM 的 Redis 连接池
 */
class RedisPool
{
    /** @var object[]|array[] 内部连接存储 */
    private $pool = [];

    /** @var object[] 协程通道池缓存 */
    private static $channels = [];

    /** @var int 当前已创建连接数 */
    private $currentSize = 0;

    /** @var int 最大连接数上限 */
    private $maxSize;

    /** @var array 连接配置 */
    private $config;

    /** @var array 记录连接最后活跃时间 */
    private $lastUsed = [];

    /** @var array<string,bool> 已发生操作异常、归还时必须丢弃的连接 */
    private $suspect = [];

    /** @var array<string,bool> 已借给调用方、尚未合法归还的连接 */
    private $borrowed = [];

    /** @var array<string,self> 多例实例（按配置哈希区分） */
    private static $instances = [];

    /**
     * 私有构造，防止外部 new
     * @param array $config
     * @param int   $maxSize
     */
    private function __construct(array $config, int $maxSize)
    {
        $this->config  = $config;
        $this->maxSize = $maxSize;
    }

    /**
     * 获取单例实例
     * @param array $config
     * @param int   $maxSize
     * @return self
     */
    public static function getInstance(array $config, int $maxSize = 10): self
    {
        // 基于配置+maxSize 做实例分桶，避免不同配置冲突
        $key = md5(json_encode([
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 6379,
            'db'   => (int)($config['dbname'] ?? 0),
            'pid'  => $config['persistent_id'] ?? '',
            'size' => $maxSize,
        ]));
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($config, $maxSize);
        }
        return self::$instances[$key];
    }

    public function getConnection(): \Redis
    {
        $coroClass = "\\Swoole\\Coroutine";
        $isCoroutine = \extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0;

        // 1. 尝试从池中获取
        if ($isCoroutine) {
            $channel = $this->getChannel();
            /** @noinspection PhpUndefinedMethodInspection */
            if (!$channel->isEmpty()) {
                /** @noinspection PhpUndefinedMethodInspection */
                $redis = $channel->pop(0.001);
                if ($redis instanceof \Redis) {
                    try {
                        $hash = spl_object_hash($redis);
                        $last = $this->lastUsed[$hash] ?? 0;
                        // 消极心跳：若空闲超过 60s 或从未记录，则 ping 校验
                        if (time() - $last < 60 || $redis->ping()) {
                            $this->borrowed[$hash] = true;
                            return $redis;
                        }
                        $this->discardConnection($redis);
                    } catch (\RedisException $e) {
                        $this->discardConnection($redis);
                    }
                }
            }
        } else {
            while (!empty($this->pool)) {
                $redis = array_pop($this->pool);
                try {
                    $hash = spl_object_hash($redis);
                    $last = $this->lastUsed[$hash] ?? 0;
                    if (time() - $last < 60 || $redis->ping()) {
                        $this->borrowed[$hash] = true;
                        return $redis;
                    }
                    $this->discardConnection($redis);
                } catch (\RedisException $e) {
                    $this->discardConnection($redis);
                }
            }
        }

        // 2. 池空且未超限，新建连接
        if ($this->currentSize < $this->maxSize) {
            // 在可 yield 的建连 I/O 前保留容量，防止多协程同时越过 maxSize。
            $this->currentSize++;
            try {
                $redis = $this->createConnection();
            } catch (\Throwable $e) {
                $this->currentSize = max(0, $this->currentSize - 1);
                throw $e;
            }
            $this->borrowed[spl_object_hash($redis)] = true;
            return $redis;
        }

        // 3. 达到上限，如果是协程则阻塞等待，FPM 则抛异常
        if ($isCoroutine) {
            /** @noinspection PhpUndefinedMethodInspection */
            $redis = $this->getChannel()->pop($this->config['timeout'] ?? 1.0);
            if ($redis instanceof \Redis) {
                $this->borrowed[spl_object_hash($redis)] = true;
                return $redis;
            }
        }

        throw new \RuntimeException("Redis 连接池已满（maxSize={$this->maxSize}）");
    }

    /**
     * 创建原生 Redis 连接
     */
    private function createConnection(): \Redis
    {
        $redis = new \Redis();
        $host       = $this->config['host'] ?? '127.0.0.1';
        $port       = (int)($this->config['port'] ?? 6379);
        $timeout    = (float)($this->config['timeout'] ?? 1.0);
        $persistent = $this->config['persistent_id'] ?? null;
        try {
            $connected = $persistent
                ? $redis->pconnect($host, $port, $timeout, $persistent)
                : $redis->connect($host, $port, $timeout);
            if (!$connected) {
                throw new \RuntimeException('Redis connect returned false');
            }
            if (!empty($this->config['password'])) {
                if (!$redis->auth($this->config['password'])) {
                    throw new \RuntimeException('Redis authentication returned false');
                }
            }
            if (!empty($this->config['dbname'])) {
                if (!$redis->select((int)$this->config['dbname'])) {
                    throw new \RuntimeException('Redis database selection returned false');
                }
            }
            if (!$redis->ping()) {
                throw new \RuntimeException('Redis ping returned false');
            }
        } catch (\Throwable $e) {
            try {
                $redis->close();
            } catch (\Throwable $_) {
            }
            throw new \RuntimeException(
                "Redis 连接失败: {$host}:{$port}, 错误: " . $e->getMessage(),
                0,
                $e
            );
        }
        return $redis;
    }

    /**
     * 获取协程通道
     * @return object 实际为 \Swoole\Coroutine\Channel
     */
    private function getChannel(): object
    {
        $key = spl_object_hash($this);
        if (!isset(self::$channels[$key])) {
            $channelClass = "\\Swoole\\Coroutine\\Channel";
            self::$channels[$key] = new $channelClass($this->maxSize);
        }
        return self::$channels[$key];
    }

    /**
     * 标记连接可疑（如操作失败时调用），强制下次获取时进行心跳检测
     */
    public function markSuspect(\Redis $redis): void
    {
        $this->suspect[spl_object_hash($redis)] = true;
    }

    public function releaseConnection(\Redis $redis): void
    {
        $hash = spl_object_hash($redis);
        if (!isset($this->borrowed[$hash])) {
            return;
        }
        unset($this->borrowed[$hash]);

        if (isset($this->suspect[$hash])) {
            $this->discardConnection($redis);
            return;
        }

        $this->lastUsed[$hash] = time();

        $coroClass = "\\Swoole\\Coroutine";
        if (\extension_loaded('swoole') && call_user_func([$coroClass, 'getCid']) > 0) {
            /** @noinspection PhpUndefinedMethodInspection */
            if (!$this->getChannel()->push($redis, $this->config['timeout'] ?? 1.0)) {
                $this->discardConnection($redis);
            }
        } else {
            if (count($this->pool) < $this->maxSize) {
                $this->pool[] = $redis;
            } else {
                $this->discardConnection($redis);
            }
        }
    }

    /**
     * 销毁不可复用连接并同步池计数/元数据。
     */
    private function discardConnection(\Redis $redis): void
    {
        $hash = spl_object_hash($redis);
        unset($this->lastUsed[$hash], $this->suspect[$hash], $this->borrowed[$hash]);

        try {
            $redis->close();
        } catch (\Throwable $_) {
        }

        $this->currentSize = max(0, $this->currentSize - 1);
    }

    /**
     * 关闭所有空闲连接并清空池
     */
    public function closeAll(): void
    {
        // 1. 清理 FPM 模式下的连接
        foreach ($this->pool as $redis) {
            try {
                $redis->close();
            } catch (\Throwable $_) {
            }
        }
        $this->pool = [];

        // 2. Worker 停机可处于非协程上下文，禁止在此调用 Channel::pop() 或创建新 Channel。
        $channelKey = spl_object_hash($this);
        if (isset(self::$channels[$channelKey])) {
            /** @noinspection PhpUndefinedMethodInspection */
            self::$channels[$channelKey]->close();
            unset(self::$channels[$channelKey]);
        }

        $this->currentSize = 0;
        $this->lastUsed = [];
        $this->suspect = [];
        $this->borrowed = [];
    }
}
