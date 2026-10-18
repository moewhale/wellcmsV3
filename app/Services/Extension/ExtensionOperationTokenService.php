<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Framework\Cache\Interfaces\CacheInterface;
use Framework\Core\Container;
use Framework\Core\Traits\StatefulTrait;

/**
 * 商店安装/升级一次性操作令牌。
 */
class ExtensionOperationTokenService
{
    use StatefulTrait;

    private const TOKEN_TTL = 900;

    /** @var CacheInterface */
    private $cache;

    public function __construct(Container $container)
    {
        $this->cache = $container->get(CacheInterface::class);
    }

    public function issue(int $userId, string $action, string $dir, int $storeId): string
    {
        if ($userId <= 0 || !in_array($action, ['download', 'upgrade'], true)
            || $dir === '' || $storeId <= 0) {
            throw new \InvalidArgumentException('Invalid extension operation token context');
        }

        $token = bin2hex(random_bytes(32));
        $payload = [
            'user_id' => $userId,
            'action' => $action,
            'dir' => $dir,
            'store_id' => $storeId,
            'expires_at' => time() + self::TOKEN_TTL,
        ];

        if (!$this->cache->set($this->cacheKey($token), $payload, self::TOKEN_TTL)) {
            throw new \RuntimeException('Failed to issue extension operation token');
        }

        return $token;
    }

    public function consume(string $token, int $userId, string $action, string $dir, int $storeId): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $cacheKey = $this->cacheKey($token);
        $lockKey = $cacheKey . ':consume';
        $lockToken = $this->cache->lock($lockKey, 30) ?? null;
        if (!$lockToken) {
            return false;
        }

        try {
            $payload = $this->cache->get($cacheKey);
            if (!is_array($payload)) {
                return false;
            }

            if (!$this->cache->delete($cacheKey)) {
                return false;
            }

            return (int)($payload['expires_at'] ?? 0) >= time()
                && (int)($payload['user_id'] ?? 0) === $userId
                && hash_equals((string)($payload['action'] ?? ''), $action)
                && hash_equals((string)($payload['dir'] ?? ''), $dir)
                && (int)($payload['store_id'] ?? 0) === $storeId;
        } finally {
            $this->cache->unlock($lockKey, $lockToken);
        }
    }

    private function cacheKey(string $token): string
    {
        return 'store:extension_operation:' . hash('sha256', $token);
    }
}
