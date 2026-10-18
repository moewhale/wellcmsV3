<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 异步上传云储存
 */
class UploadToCloudJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Services\Storage\FileStorageService */
    protected $fileStorageService;

    // TaskExecutor 会通过 Container 自动注入这些依赖
    public function __construct(
        \App\Services\Storage\FileStorageService $fileStorageService
    ) {
        $this->fileStorageService = $fileStorageService;
    }

    /** 上传链路保护：单文件原语不删本地；真实失败交调度器有限重试。 */
    public function handle(int $fileStorageId, array $cfg): array
    {
        if ($fileStorageId <= 0) throw new \InvalidArgumentException('Missing file storage ID');
        $diskKey = (string)($cfg['diskKey'] ?? ($cfg['default'] ?? ''));
        $file = $this->fileStorageService->readOnMaster($fileStorageId);
        if (!$file) return ['status' => 'skipped', 'reason' => 'db_not_found'];
        $resolved = $this->fileStorageService->resolveStoredFile($file);
        $expected = $cfg['artifact'] ?? $resolved['artifact'];
        if (!is_array($expected)) throw new \InvalidArgumentException('Invalid expected cloud artifact');
        $result = $this->fileStorageService->syncToCloud($fileStorageId, $diskKey, $expected);
        if ($result['state'] === 'stale') return ['status' => 'skipped', 'reason' => 'stale'];
        return ['status' => 'success', 'reason' => $result['state'], 'locator' => $result['locator']];
    }
}
