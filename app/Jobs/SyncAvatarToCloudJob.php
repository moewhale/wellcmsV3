<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 异步同步头像到云储存
 */
class SyncAvatarToCloudJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var object */
    protected $userService;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var object */
    protected $storageManager;

    public function __construct(
        \App\Services\Auth\UserService $userService,
        \Framework\Core\Container $container,
        \App\Services\Storage\StorageManager $storageManager
    ) {
        $this->userService = $userService;
        $this->container = $container;
        $this->storageManager = $storageManager;
    }

    /**
     * @param int $userId 用户ID
     * @param string $localPath 本地全路径
     * @param string $cloudKey 云端存储路径 (e.g. avatar/202601/1705900000.png)
     * @param array $uploadConfig 上传配置
     */
    public function handle(int $userId, string $localPath, string $cloudKey, array $uploadConfig, int $expectedAvatar = 0, string $expectedHash = '', int $expectedSize = -1): array
    {
        $diskKey = (string)($uploadConfig['default'] ?? '');
        $cloudDisk = $this->storageManager->disk($diskKey);
        if ($diskKey === '' || $diskKey === 'local' || $cloudDisk instanceof \App\Services\Storage\Drivers\LocalStorage) throw new \RuntimeException('Avatar cloud driver is unavailable');
        $avatar = $expectedAvatar > 0 ? $expectedAvatar : (int)pathinfo($localPath, PATHINFO_FILENAME);
        $user = $this->userService->readOnMaster($userId);
        if (!$user || (int)$user['avatar'] !== $avatar) return ['status' => 'skipped', 'reason' => 'stale_avatar'];
        // 上传链路保护：沿用任务冻结配置，每次执行独立构造，不污染容器单例。
        $helper = $this->container->makeTransient(\App\Services\Storage\Support\FileSystemHelper::class, ['cfg' => $uploadConfig, 'allowedExt' => []]);
        $path = $helper->resolveStoredTarget($localPath);
        $key = ltrim((string)($uploadConfig['disks']['local']['url'] ?? ''), '/') . 'avatar/' . date('Ym', $avatar) . '/' . $avatar . '.png';
        if (!hash_equals($key, $cloudKey)) throw new \RuntimeException('Avatar cloud locator mismatch');
        if (!is_file($path)) {
            if ((int)$user['avatar_status'] === 1 && $cloudDisk->exists($cloudKey)) return ['status' => 'success', 'reason' => 'already_completed'];
            throw new \RuntimeException('Avatar local source is missing');
        }
        $path = $helper->resolveStoredFile($path);
        $sha = \Framework\Utils\FileHasher::sha256($path, 'hex');
        $size = filesize($path);
        if ($size === false) throw new \RuntimeException('Cannot read avatar source size');
        if (($expectedHash !== '' && !hash_equals($expectedHash, $sha)) || ($expectedSize >= 0 && $expectedSize !== $size)) return ['status' => 'skipped', 'reason' => 'stale_avatar_artifact'];
        if ((int)$user['avatar_status'] !== 1) {
            $stream = $this->storageManager->disk('local')->readStream($path);
            if (!is_resource($stream)) throw new \RuntimeException('Cannot open avatar upload stream');
            try {
                if (!$cloudDisk->writeStream($cloudKey, $stream)) throw new \RuntimeException('Avatar cloud write returned false');
            } finally { fclose($stream); }
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) !== $size || !hash_equals($sha, \Framework\Utils\FileHasher::sha256($path, 'hex'))) return ['status' => 'skipped', 'reason' => 'stale_avatar_artifact'];
            if (!$this->userService->completeAvatarCloud($userId, $avatar)) return ['status' => 'skipped', 'reason' => 'stale_avatar'];
        }
        // 独立头像沿用原本地清理职责；失败仅重试清理，不重新上传已提交状态。
        $this->userService->releaseAvatarLocalCopy($userId, $avatar, $path, $diskKey, $sha, $size);
        return ['status' => 'success', 'reason' => 'sync_successfully'];
    }
}
