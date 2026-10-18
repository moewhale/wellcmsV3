<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Jobs;

/**
 * 按原始上传或已提交清洗产物的身份验证本地文件，不改写预期哈希和大小。
 * 上传链路保护：本次变更由用户依据全链路修复文档明确授权；不得另行增加投递入口。
 */
class VerifyIntegrityJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var \App\Services\Storage\FileStorageService */
    protected $fileStorageService;
    /** @var \Framework\Logger\LoggerInterface */
    protected $logger;

    // TaskExecutor 会通过 Container 自动注入这些依赖
    public function __construct(
        \App\Services\Storage\FileStorageService $fileStorageService,
        \Framework\Logger\LoggerInterface $logger
    ) {
        $this->fileStorageService = $fileStorageService;
        $this->logger = $logger;
    }

    public function handle(int $fileStorageId): array
    {
        if ($fileStorageId <= 0) {
            throw new \InvalidArgumentException("Missing File StorageId ID");
        }
        return $this->fileStorageService->withFileProtection($fileStorageId, function () use ($fileStorageId): array {
            return $this->verify($fileStorageId);
        });
    }

    private function verify(int $fileStorageId): array
    {
        // 与清洗/发布共用对象保护；不能从延迟缓存拿旧产物身份。
        $file = $this->fileStorageService->readOnMaster($fileStorageId);
        if (empty($file)) {
            $this->logger->warning("VerifyIntegrityJob: File not found in DB", ['file_storage_id' => $fileStorageId]);
            return ['status' => 'skipped', 'reason' => 'db_not_found'];
        }

        if ((int)($file['cloud_type'] ?? 0) > 0) {
            return ['status' => 'skipped', 'reason' => 'cloud_storage'];
        }

        // 主库读取已将数据库二进制哈希格式化为 hex；清洗后只验证已提交产物。
        $cleaned = 1 === (int)($file['exif_cleaned'] ?? 0);
        $expectedHash = (string)($file[$cleaned ? 'newhash' : 'filehash'] ?? '');
        $expectedSize = (int)($file[$cleaned ? 'newsize' : 'filesize'] ?? -1);
        if (!preg_match('/^[a-f0-9]{64}$/i', $expectedHash)
            || strspn($expectedHash, '0') === 64
            || $expectedSize < 0
            || ($cleaned && $expectedSize === 0)) {
            throw new \RuntimeException('VerifyIntegrityJob: Expected file identity is unavailable');
        }

        $filePath = $this->fileStorageService->resolveStoredFilePath((string)($file['path'] ?? ''));
        clearstatcache(true, $filePath);
        $filesize = filesize($filePath);
        if ($filesize === false) {
            throw new \RuntimeException('VerifyIntegrityJob: Failed to read file size');
        }
        $filehash = \Framework\Utils\FileHasher::sha256($filePath, 'hex');
        if (!hash_equals(strtolower($expectedHash), $filehash) || $filesize !== $expectedSize) {
            $note = 'File hash or size does not match the expected identity.';
            if ((int)($file['is_reviewed'] ?? 0) !== 2 || (string)($file['review_note'] ?? '') !== $note) {
                if ($this->fileStorageService->update($fileStorageId, ['is_reviewed' => 2, 'review_note' => $note]) <= 0) {
                    throw new \RuntimeException('VerifyIntegrityJob: Failed to persist integrity rejection');
                }
            }
            throw new \RuntimeException('VerifyIntegrityJob: File integrity mismatch');
        }

        return ['status' => 'success', 'reason' => 'operation_successfully'];
    }
}

/* $scheduler->createTask([
    'className' => 'App\\Jobs\\VerifyIntegrityJob',
    'methodName' => 'handle',
    'args' => [
        'file_storage_id' => $fileStorageId
    ],
    'priority' => 5,
    'retryDelay' => 10,
    'maxRetries' => 3
]); */
