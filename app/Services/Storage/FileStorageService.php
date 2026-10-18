<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

use Framework\Utils\Validator;
use Framework\Exception\BusinessException;
use Framework\Exception\Infra\DatabaseException;
use Framework\Database\Interfaces\DatabaseInterface;

/*
 * 上传链路保护（用户要求，2026-10-10；见根目录 AGENTS.md）：
 * 未经用户明确授权，AI 禁止修改文件查重、缓存格式/失效键、存储及引用计数契约。
 * 此服务由所有插件共用；审计、重构、优化或修复测试均不构成授权，必须保留此备注。
 */
class FileStorageService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var \Framework\Core\Container */
    protected $container;
    /** @var \App\Models\FileStorageModel */
    protected $dbModel;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var \App\Utils\I18nDateFormatter */
    protected $i18nDateFmt;
    /** @var array */
    protected $cacheConfig;
    /** @var array */
    protected $uploadConfig;
    /** @var \App\Services\Storage\Support\FileSystemHelper */
    protected $fsHelper;
    /** @var \App\Services\Storage\StorageManager */
    protected $storageManager;
    /** @var DatabaseInterface */
    protected $database;
    /** @var int */
    protected $ttl;

    public function __construct(
        \Framework\Core\Container $container,
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Utils\I18nDateFormatter $i18nDateFmt,
        array $cacheConfig,
        array $uploadConfig,
        \App\Services\Storage\StorageManager $storageManager,
        DatabaseInterface $database
    ) {
        // hook app_Services_FileStorageService_construct_start.php
        $this->cacheConfig = $cacheConfig;
        $this->uploadConfig = $uploadConfig;
        $this->container = $container;
        $this->cache = $cache;
        $this->i18nDateFmt = $i18nDateFmt;
        $this->storageManager = $storageManager;
        $this->database = $database;
        $this->ttl = $this->cacheConfig['file_ttl'] ?? 3600;
        $this->fsHelper = new \App\Services\Storage\Support\FileSystemHelper($this->uploadConfig);
        // hook app_Services_FileStorageService_construct_end.php
    }

    private function getModel()
    {
        if ($this->dbModel !== null) {
            return $this->dbModel;
        }
        $resolved = $this->container->get(\App\Models\FileStorageModel::class);
        // 首次获取可能让出协程；保留已经建立的模型引用。
        return $this->dbModel = $this->dbModel ?? $resolved;
    }

    public function insert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        $filehash = $data['filehash'] ?? '';
        if (empty($filehash)) {
            $result = $this->getModel()->insert($data);
            if (!$result) throw new DatabaseException('FileStorageService -> insert(): data writing failed');
            return $result;
        }

        // 基于 filehash 的原子锁，实现 Check-and-Insert
        $lockKey = 'lock:file_storage:insert:' . $filehash;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $exists = $this->getModel()->readFromMaster(['filehash' => $filehash]);
            if (!empty($exists)) {
                $this->invalidateStorageCache((int)$exists['id'], $filehash);
                return (int)$exists['id'];
            }

            if (isset($data['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($data['create_ip'] ?? '');
                $data['create_ip'] = $ip2bin;
            }

            $result = $this->getModel()->insert($data);
            if (!$result) throw new DatabaseException('FileStorageService -> insert(): data writing failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->invalidateStorageCache((int)$result, $filehash);
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkInsert(array $data): int
    {
        Validator::make(['data' => $data], ['data' => 'required|array']);

        // hook app_Services_FileStorageService_bulkInsert_start.php

        $result = $this->getModel()->bulkInsert($data);
        if (!$result) throw new DatabaseException('FileStorageService -> bulkInsert(): data writing failed');

        foreach ($data as $row) {
            if (isset($row['filehash'])) $this->invalidateStorageCache((int)($row['id'] ?? 0), $row['filehash']);
        }

        // hook app_Services_FileStorageService_bulkInsert_end.php

        return $result;
    }

    public function update(int $id, array $update = []): int
    {
        Validator::make(['id' => $id, 'update' => $update], ['id' => 'required|int', 'update' => 'required|array']);

        $lockKey = 'lock:file_storage:update:' . $id;
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return 0;

        try {
            $oldData = $this->getModel()->readOnMaster($id);
            if (empty($oldData)) return 0;

            if (isset($update['create_ip'])) {
                list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($update['create_ip'] ?? '');
                $update['create_ip'] = $ip2bin;
            }

            $result = $this->getModel()->update(['id' => $id], $update);
            if ($result === 0) throw new BusinessException('FileStorageService -> update() Update failed');

            if (!empty($this->cacheConfig['stores'])) {
                $this->invalidateStorageCache($id, $oldData['filehash']);
                if (isset($update['filehash']) && $update['filehash'] !== $oldData['filehash']) {
                    $this->invalidateStorageCache($id, $update['filehash']);
                }
            }

            return $result;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    // IP 字段务必保持与数据库一致的格式（通常为二进制），以确保查询和缓存的正确性
    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        Validator::make(['update' => $update], ['update' => 'required|array']);

        $oldRows = [];
        if (!empty($this->cacheConfig['stores'])) {
            foreach ($update as $row) {
                if (!array_key_exists($keyColumn, $row)) continue;
                $old = $this->getModel()->readFromMaster([$keyColumn => $row[$keyColumn]]);
                if ($old) $oldRows[(int)$old['id']] = $old;
            }
        }
        $result = $this->getModel()->bulkUpdate($update, $keyColumn, $wheres);
        if ($result === 0) throw new BusinessException('FileStorageService -> bulkUpdate(): update failed');
        foreach ($oldRows as $old) $this->invalidateStorageCache((int)$old['id'], (string)$old['filehash']);
        foreach ($update as $row) {
            if (isset($row['filehash'], $row['id'])) $this->invalidateStorageCache((int)$row['id'], (string)$row['filehash']);
        }
        return $result;
    }

    /**
     * 增加引用计数
     */
    public function incrementRefCount($storageId): bool
    {
        return $this->withFileProtection((int)$storageId, function () use ($storageId): bool {
            $updated = $this->getModel()->update(
                ['id' => $storageId, 'ref_count' => ['>=' => 0]], ['ref_count+' => 1]
            );
            if ($updated !== 1) throw new BusinessException('Storage is missing or being cleaned');
            $this->invalidateStorageCache((int)$storageId);
            return true;
        });
    }

    /** 按实际解除的关联数原子扣减；零计数不冒充成功。 */
    public function decrementRefCount($storageId): bool
    {
        return $this->withFileProtection((int)$storageId, function () use ($storageId): bool {
            $operation = function () use ($storageId): bool {
                $updated = $this->getModel()->update(
                    ['id' => $storageId, 'ref_count' => ['>' => 0]], ['ref_count-' => 1]
                );
                if ($updated !== 1) return false;
                $this->invalidateStorageCache((int)$storageId);
                $releasedCount = 1;
                // hook app_Services_Storage_FileStorageService_referenceReleased.php
                return true;
            };
            return $this->database->inTransaction() ? $operation() : $this->database->transaction($operation, 1, '');
        });
    }

    public function read(array $condition = [], array $orderby = [], array $fields = ['*']): array
    {
        $data = $this->getModel()->read($condition, $orderby, $fields);
        if (!$data) return [];
        $this->format($data);
        return $data;
    }

    /** 上传链路保护：完整记录缓存后再投影，拒绝旧的局部/异常缓存。 */
    public function readByCache(int $id, array $orderby = [], array $fields = ['*']): array
    {
        if ($id <= 0) return [];
        if (empty($this->cacheConfig['stores']) || ($this->database && $this->database->inTransaction())) {
            $row = $this->readOnMaster($id);
            return $fields === ['*'] ? $row : array_intersect_key($row, array_fill_keys($fields, true));
        }
        $data = $this->cache->cacheWithLock(
            'file:' . $id,
            'lock:file:read:' . $id,
            function () use ($id, $orderby) {
                $row = $this->readOnMaster($id);
                return $row ?: null;
            },
            3,
            $this->ttl
        );
        $required = ['id', 'filehash', 'filesize', 'newhash', 'newsize', 'path', 'url', 'cloud_type',
            'cloud_url', 'ref_count', 'is_reviewed', 'exif_cleaned', 'mime'];
        if (!is_array($data) || array_diff($required, array_keys($data))) {
            $this->cache->delete('file:' . $id);
            $data = $this->readOnMaster($id);
            if ($data) $this->cache->set('file:' . $id, $data, $this->ttl);
        }
        if (!$data || in_array('*', $fields, true)) return $data ?: [];
        return array_intersect_key($data, array_fill_keys($fields, true));
    }

    public function readOnMaster(int $id): array
    {
        $row = $this->getModel()->readOnMaster($id);
        if ($row) $this->format($row);
        return $row;
    }

    /** 上传链路保护：有界主库批读，沿用单条读取的格式，不读取或回填缓存。 */
    public function readManyOnMaster(array $ids): array
    {
        if (!$ids) return [];
        if (count($ids) > 100) throw new \InvalidArgumentException('Invalid storage master batch');
        $locators = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('Invalid storage master ID');
            $locators[$id] = ['id' => $id];
        }
        $result = [];
        foreach ($this->getModel()->readManyFromMaster(array_values($locators), ['*']) as $row) {
            $this->format($row);
            $result[(int)$row['id']] = $row;
        }
        return $result;
    }

    public function find(array $condition = [], array $orderby = [], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        Validator::make(['condition' => $condition, 'orderby' => $orderby], ['condition' => 'array', 'orderby' => 'array']);

        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_FileStorageService_find_start.php

        $datalist = $this->getModel()->find($condition, $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_find_end.php

        return $datalist;
    }

    public function findByIds(array $ids, bool $desc = true, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        $orderby = true == $desc ? -1 : 1;

        // hook app_Services_FileStorageService_findByIds_start.php

        $datalist = $this->getModel()->find(['id' => $ids], ['id' => $orderby], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_findByIds_end.php

        return $datalist;
    }

    public function findPaged(array $condition = [], array $orderby = ['id' => -1], int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        if (isset($condition['create_ip'])) {
            list($ip, $ip2bin) = \Framework\Utils\IpHelper::normalizeIp($condition['create_ip'] ?? '');
            $condition['create_ip'] = $ip2bin;
        }

        // hook app_Services_FileStorageService_findPaged_start.php

        $datalist = $this->getModel()->find(['id' => $condition], $orderby, $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        // hook app_Services_FileStorageService_findPaged_before.php

        foreach ($datalist as &$data) {
            $this->format($data);
        }

        // hook app_Services_FileStorageService_findPaged_end.php

        return $datalist;
    }

    public function findByReviewed(int $isReviewed, int $page = 1, int $pageSize = 20, string $indexKey = 'id', array $fields = ['*']): array
    {
        // hook app_Services_FileStorageService_findByReviewed_start.php

        $datalist = $this->getModel()->find(['is_reviewed' => $isReviewed], ['id' => -1], $page, $pageSize, $indexKey, $fields);
        if (empty($datalist)) return [];

        foreach ($datalist as &$data) $this->format($data);
        unset($data);

        // hook app_Services_FileStorageService_findByReviewed_end.php

        return $datalist;
    }

    // 存在附件和图片以及云储存，在 controller 关联完成
    public function delete(array $condition = []): int
    {
        // hook app_Services_FileStorageService_delete_start.php
        if (empty($this->cacheConfig['stores'])) {
            $result = $this->getModel()->delete($condition);
        } else {
        $operation = function () use ($condition): int {
            $deleted = 0;
            do {
                // 当前事务的普通模型读沿用 ProxyDriver 的主库事务连接。
                $rows = $this->getModel()->find($condition, ['id' => 1], 1, 100, '', ['id', 'filehash']);
                if (!$rows) break;
                $ids = array_values(array_map('intval', array_column($rows, 'id')));
                $count = $this->getModel()->delete(['id' => $ids] + $condition);
                if ($count !== count($ids)) throw new DatabaseException('Storage deletion changed while invalidating cache');
                $deleted += $count;
                foreach ($rows as $row) $this->invalidateStorageCache((int)$row['id'], (string)$row['filehash']);
            } while (count($rows) === 100);
            return $deleted;
        };
        $result = $this->database->inTransaction() ? $operation() : $this->database->transaction($operation, 1, '');
        }
        // hook app_Services_FileStorageService_delete_end.php
        return $result;
    }

    public function deleteWithFileBulk(array $ids): bool
    {
        $datalist = $this->findByIds($ids, false, 1, count($ids));
        if (empty($datalist)) return false;

        foreach ($datalist as $data) {
            if (!$this->deleteWithFile($data)) return false;
        }

        return true;
    }

    /**
     * 原子递减引用计数并在为0时清理物理文件
     */
    public function decrementAndCleanup(int $storageId): bool
    {
        if ($storageId <= 0) return false;
        if (!$this->decrementRefCount($storageId)) {
            return !$this->getModel()->readOnMaster($storageId);
        }
        return $this->runAfterCommit(function () use ($storageId): bool {
            return $this->cleanupIfUnreferenced($storageId);
        });
    }

    /** 先清物理对象再删除零引用记录；失败保留准确定位供既有任务恢复。 */
    public function cleanupIfUnreferenced(int $storageId): bool
    {
        if ($storageId <= 0) return false;
        return $this->withFileProtection($storageId, function () use ($storageId): bool {
            $data = $this->getModel()->readOnMaster($storageId);
            if (!$data) return true;
            if ((int)$data['ref_count'] > 0) return true;
            if ((int)$data['ref_count'] !== 0) throw new \RuntimeException('Invalid file reference count');
            $references = $this->container->get(AttachmentService::class)->findStorageReferences($storageId, 0, 1);
            if ($references) throw new \RuntimeException('Zero reference count conflicts with actual attachments');
            if ((int)($data['cloud_type'] ?? 0) !== 0) {
                $this->format($data);
                $file = $this->resolveStoredFile($data);
                // 云插件负责确认远端结果未知时的保护/恢复；未接入不得丢弃唯一定位。
                $cloudDeleted = false;
                // hook app_Services_Storage_FileStorageService_deleteCloudObject.php
                if (!$cloudDeleted) return false;
                if (!$this->storageManager->deleteFile((string)$data['path'], 0)) return false;
            } elseif (!empty($data['path']) && !$this->storageManager->deleteFile((string)$data['path'], 0)) {
                return false;
            }
            $this->assertFileProtection($storageId);
            $deleted = $this->getModel()->delete(['id' => $storageId, 'ref_count' => 0]);
            if ($deleted !== 1) throw new \RuntimeException('File identity changed during object cleanup');
            $this->invalidateStorageCache($storageId, $data['filehash'] ?? '');
            return true;
        });
    }

    /** 旧清理意图失去存储行后的补偿：缺少身份依据时保留，不按当前默认盘猜测删除。 */
    public function cleanupDetachedFile(int $storageId, array $locator): bool
    {
        if ($storageId <= 0) throw new \InvalidArgumentException('Invalid detached storage ID');
        return $this->withFileProtection($storageId, function () use ($storageId, $locator): bool {
            if ($this->getModel()->readOnMaster($storageId)) return $this->cleanupIfUnreferenced($storageId);
            $type = (int)($locator['cloudType'] ?? 0);
            $path = (string)($locator['localPath'] ?? '');
            $local = $path === '' ? '' : $this->fsHelper->resolveStoredTarget($path);
            if ($type === 0 && ($local === '' || !is_file($local))) return true;
            $diskKey = (string)($locator['diskKey'] ?? '');
            $objectKey = (string)($locator['objectKey'] ?? '');
            if ($type === 1 && (!$this->validDiskKey($diskKey) || $diskKey === 'local' || !$this->validObjectKey($objectKey))) return false;
            if ($type === 2) return false; // 图床删除合同仍由对应插件提供。
            $detachedCleanupVerified = false;
            // Hook 必须证明旧对象未被复用，且远端删除/不存在已确认；结果未知不能置 true。
            // hook app_Services_Storage_FileStorageService_cleanupDetachedFile.php
            if (!$detachedCleanupVerified) return false;
            if ($local !== '' && is_file($local) && !$this->storageManager->disk('local')->delete($local)) throw new \RuntimeException('Cannot clean verified detached local file');
            return true;
        });
    }

    public function deleteWithFile(array $data = []): bool
    {
        if (empty($data['id'])) return false;
        $storageId = (int)$data['id'];
        return $this->runAfterCommit(function () use ($storageId): bool {
            $current = $this->getModel()->readOnMaster($storageId);
            if (!$current) return true;
            if ((int)$current['ref_count'] !== 0) return false;
            return $this->cleanupIfUnreferenced($storageId);
        });
    }

    /**
     * 只在最外层事务提交后执行不可回滚副作用。
     */
    private function runAfterCommit(callable $callback): bool
    {
        if (!$this->database->inTransaction()) return (bool)$callback();
        if (method_exists($this->database, 'afterCommit')) {
            $this->database->afterCommit(function () use ($callback): void {
                if (!$callback()) {
                    throw new \RuntimeException('Post-commit storage cleanup failed');
                }
            });
            return true;
        }
        throw new \RuntimeException('Physical cleanup requires an afterCommit boundary');
    }

    /** @param mixed $hash */
    private function invalidateStorageCache(int $id, $hash = ''): void
    {
        if (empty($this->cacheConfig['stores'])) return;
        if (is_resource($hash)) $hash = stream_get_contents($hash);
        $hex = is_string($hash) && strlen($hash) === 64 && ctype_xdigit($hash)
            ? strtolower($hash) : bin2hex((string)$hash);
        $invalidate = function () use ($id, $hex): void {
            if ($id > 0) $this->cache->delete('file:' . $id);
            if ($hex === '') return;
            $this->cache->delete('filehash:' . $hex);
            $this->cache->delete('hash:index:' . $hex);
        };
        if ($this->database && $this->database->inTransaction() && method_exists($this->database, 'afterCommit')) {
            $this->database->afterCommit($invalidate);
            return;
        }
        $invalidate();
    }

    public function count( array $condition= []): int
    {
        return $this->getModel()->count($condition);
    }

    public function maxid(): int
    {
        $maxId = $this->getState('maxId');
        if (null !== $maxId) return $maxId;
        // hook app_Services_FileStorageService_maxid_start.php
        $maxId = $this->getModel()->maxid();
        $this->setState('maxId', $maxId);
        // hook app_Services_FileStorageService_maxid_end.php
        return $maxId;
    }

    /**
     * 将临时文件转正
     * @param string $tempPath
     * @return array [newPath, newUrl]
     */
    public function promoteFile(string $tempPath, bool $preserveSource = false): array
    {
        return $this->fsHelper->promoteFile($tempPath, $preserveSource);
    }

    /**
     * 在读取、哈希或转正前解析受管临时文件。
     */
    public function resolveTempFilePath(string $path): string
    {
        return $this->fsHelper->resolveManagedTempFile($path);
    }

    /**
     * 在下载或物理读取前解析正式存储文件。
     */
    public function resolveStoredFilePath(string $path): string
    {
        return $this->fsHelper->resolveStoredFile($path);
    }

    /** 上传链路保护：仅解析宿主查得的记录；原始 URL 不被展示地址覆盖。 */
    public function resolveStoredFile(array $storage): array
    {
        $cleaned = 1 === (int)($storage['exif_cleaned'] ?? 0);
        $hash = (string)($storage[$cleaned ? 'newhash' : 'filehash'] ?? '');
        $size = $storage[$cleaned ? 'newsize' : 'filesize'] ?? null;
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash) || strspn($hash, '0') === 64
            || $size === null || (int)$size < 0 || ($cleaned && (int)$size === 0)) {
            throw new \RuntimeException('Stored file artifact is unavailable');
        }
        $cloudType = (int)($storage['cloud_type'] ?? 0);
        $diskKey = (string)($storage['disk_key'] ?? '');
        $objectKey = (string)($storage['cloud_url'] ?? '');
        if ($cloudType === 1 && ($diskKey === 'local' || !$this->validDiskKey($diskKey) || !$this->validObjectKey($objectKey))) {
            throw new \RuntimeException('Stored cloud locator is unavailable');
        }
        return [
            'storageId' => (int)($storage['id'] ?? 0),
            'localPath' => $this->fsHelper->resolveStoredTarget((string)($storage['path'] ?? '')),
            'stableUrl' => (string)($storage['url'] ?? ''),
            'cloudType' => $cloudType,
            'diskKey' => $diskKey,
            'objectKey' => $objectKey,
            'artifact' => ['sha256' => $hash, 'size' => (int)$size, 'mime' => (string)($storage['mime'] ?? '')],
        ];
    }

    /** 来源已由业务权限及整篇状态选择；返回流的所有权交给调用者。 */
    public function openStoredFile(array $storage, string $diskKey): array
    {
        if ($diskKey === 'local') {
            try { $path = $this->resolveStoredFilePath((string)($storage['path'] ?? '')); }
            catch (\RuntimeException $missingLocal) { throw new \Framework\Exception\Http\NotFoundException('file_not_exists', $missingLocal); }
            $stream = $this->storageManager->disk('local')->readStream($path);
        } else {
            $file = $this->resolveStoredFile($storage);
            if ($file['cloudType'] !== 1 || !hash_equals($file['diskKey'], $diskKey)) {
                throw new \RuntimeException('Stored file does not belong to the requested cloud disk');
            }
            $stream = $this->requireCloudDisk($diskKey)->readStream($file['objectKey']);
        }
        if (!is_resource($stream)) throw new \Framework\Exception\Http\NotFoundException('file_not_exists');
        $stat = fstat($stream);
        $cleaned = 1 === (int)($storage['exif_cleaned'] ?? 0);
        $size = $storage[$cleaned ? 'newsize' : 'filesize'] ?? null;
        if ($diskKey === 'local' && is_array($stat) && isset($stat['size'])) $size = $stat['size'];
        return ['stream' => $stream, 'size' => $size === null ? null : (int)$size,
            'mime' => (string)($storage['mime'] ?? 'application/octet-stream')];
    }

    /** 同一文件的绑定、处理、恢复与删除共用保护；外层事务提交/回滚后才释放。 */
    public function withFileProtection(int $storageId, callable $operation)
    {
        if ($storageId <= 0) throw new \InvalidArgumentException('Invalid file storage ID');
        $held = $this->getState('fileProtection', []);
        if (isset($held[$storageId])) {
            if ($held[$storageId]['deadline'] <= microtime(true)) throw new \RuntimeException('File protection expired');
            return $operation();
        }
        $key = 'lock:file_storage:object:' . $storageId;
        $token = $this->cache->lock($key, 120);
        if (!$token) throw new \RuntimeException('Cannot protect file storage object');
        $held[$storageId] = ['token' => $token, 'deadline' => microtime(true) + 110];
        $this->setState('fileProtection', $held);
        $release = function () use ($storageId, $key, $token): void {
            $held = $this->getState('fileProtection', []);
            if (isset($held[$storageId]) && $held[$storageId]['token'] === $token) {
                unset($held[$storageId]);
                $this->setState('fileProtection', $held);
            }
            $this->cache->unlock($key, $token);
        };
        $deferred = false;
        try {
            if ($this->database->inTransaction()) {
                if (!method_exists($this->database, 'afterCommit') || !method_exists($this->database, 'afterRollback')) {
                    throw new \RuntimeException('File protection requires transaction completion callbacks');
                }
                $this->database->afterCommit($release);
                $this->database->afterRollback($release);
                $deferred = true;
            }
            return $operation();
        } finally {
            if (!$deferred) $release();
        }
    }

    public function withFileProtections(array $storageIds, callable $operation)
    {
        $ids = array_values(array_unique(array_map('intval', $storageIds)));
        sort($ids, SORT_NUMERIC);
        $acquire = function (int $index) use (&$acquire, $ids, $operation) {
            if ($index === count($ids)) {
                foreach ($ids as $id) $this->assertFileProtection($id);
                return $operation();
            }
            return $this->withFileProtection($ids[$index], function () use ($index, &$acquire) {
                return $acquire($index + 1);
            });
        };
        return $acquire(0);
    }

    /** 单文件云同步；成功也保留本地，整篇标记由所属模块提交。 */
    public function syncToCloud(int $storageId, string $diskKey, array $expected): array
    {
        return $this->withFileProtection($storageId, function () use ($storageId, $diskKey, $expected): array {
            $storage = $this->readOnMaster($storageId);
            if (!$storage) return ['state' => 'stale'];
            $file = $this->resolveStoredFile($storage);
            if (!$this->matchesArtifact($file['artifact'], $expected)) return ['state' => 'stale'];
            if (1 !== (int)($storage['is_reviewed'] ?? 0)) throw new \RuntimeException('File review is not ready');
            if (!empty($storage['is_image']) && 1 !== (int)($storage['exif_cleaned'] ?? 0)) {
                throw new \RuntimeException('Image cleanup must commit before cloud upload');
            }
            $disk = $this->requireCloudDisk($diskKey);
            $objectKey = $this->objectKeyForUrl($file['stableUrl']);
            if ($file['cloudType'] !== 0) {
                if ($file['cloudType'] !== 1 || $file['diskKey'] !== $diskKey || $file['objectKey'] !== $objectKey) {
                    throw new \RuntimeException('Cloud file relocation requires explicit migration');
                }
                return ['state' => 'already_completed', 'locator' => $file, 'artifact' => $file['artifact']];
            }
            $path = $this->resolveStoredFilePath((string)$storage['path']);
            $this->verifyArtifactFile($path, $expected);
            $this->assertCloudBudget($diskKey, $storageId);
            $stream = $this->storageManager->disk('local')->readStream($path);
            if (!is_resource($stream)) throw new \RuntimeException('Cannot open local upload source');
            try {
                if (!$disk->writeStream($objectKey, $stream)) throw new \RuntimeException('Cloud write failed');
            } finally {
                fclose($stream);
            }
            $this->assertFileProtection($storageId);
            $condition = ['id' => $storageId, 'cloud_type' => 0, 'filehash' => hex2bin((string)$storage['filehash']),
                'newhash' => hex2bin((string)$storage['newhash']), 'newsize' => (int)$storage['newsize']];
            $updated = $this->getModel()->update($condition, ['cloud_type' => 1, 'disk_key' => $diskKey,
                'cloud_url' => $objectKey, 'updated_at' => time()]);
            if ($updated !== 1) throw new \RuntimeException('Cloud file result was not committed');
            $this->invalidateStorageCache($storageId, $storage['filehash']);
            $file['cloudType'] = 1;
            $file['diskKey'] = $diskKey;
            $file['objectKey'] = $objectKey;
            return ['state' => 'completed', 'locator' => $file, 'artifact' => $file['artifact']];
        });
    }

    /** 清洗文件发布后补交元数据，原始 hash/size 永远不被覆盖。 */
    public function commitCleanedArtifact(int $storageId, array $source, array $prepared): array
    {
        return $this->withFileProtection($storageId, function () use ($storageId, $source, $prepared): array {
            $row = $this->readOnMaster($storageId);
            if (!$row) return ['state' => 'stale'];
            $file = $this->resolveStoredFile($row);
            if ((int)$row['exif_cleaned'] === 1 && $this->matchesArtifact($file['artifact'], $prepared['artifact'])) {
                return ['state' => 'already_completed'];
            }
            if ((int)$row['cloud_type'] !== 0 || !$this->matchesArtifact($file['artifact'], $source)) {
                return ['state' => 'stale'];
            }
            $this->verifyArtifactFile($file['localPath'], $prepared['artifact']);
            $updated = $this->getModel()->update(['id' => $storageId, 'filehash' => hex2bin($source['sha256']), 'exif_cleaned' => 0], [
                'newhash' => hex2bin($prepared['artifact']['sha256']), 'newsize' => $prepared['artifact']['size'],
                'mime' => $prepared['artifact']['mime'], 'width' => $prepared['width'], 'height' => $prepared['height'],
                'exif_cleaned' => 1, 'updated_at' => time(),
            ]);
            if ($updated !== 1) throw new \RuntimeException('Image cleanup metadata was not committed');
            $this->invalidateStorageCache($storageId, $row['filehash']);
            return ['state' => 'completed'];
        });
    }

    public function restoreLocalCopy(int $storageId, array $expected): array
    {
        return $this->withFileProtection($storageId, function () use ($storageId, $expected): array {
            $storage = $this->readOnMaster($storageId);
            if (!$storage) return ['state' => 'stale'];
            $file = $this->resolveStoredFile($storage);
            if (!$this->matchesArtifact($file['artifact'], $expected)) return ['state' => 'stale'];
            $path = $this->fsHelper->resolveStoredTarget((string)$storage['path'], true);
            if (is_file($path)) {
                $this->verifyArtifactFile($path, $expected);
                return ['state' => 'already_present', 'path' => $path];
            }
            if ($file['cloudType'] !== 1) throw new \RuntimeException('No cloud source for local restoration');
            $this->assertCloudBudget($file['diskKey'], $storageId);
            $source = $this->openStoredFile($storage, $file['diskKey']);
            $workFile = $path . '.restore-' . bin2hex(random_bytes(12));
            try {
                if (!$this->storageManager->disk('local')->writeStream($workFile, $source['stream'])) {
                    throw new \RuntimeException('Cannot write local restoration work file');
                }
                $this->verifyArtifactFile($workFile, $expected);
                $this->assertFileProtection($storageId);
                $this->fsHelper->resolveStoredTarget((string)$storage['path'], true);
                if (file_exists($path) || !rename($workFile, $path)) {
                    throw new \RuntimeException('Cannot publish local restoration');
                }
                return ['state' => 'restored', 'path' => $path];
            } finally {
                fclose($source['stream']);
                if (is_file($workFile)) $this->storageManager->disk('local')->delete($workFile);
            }
        });
    }

    /** 共享引用判定默认未知，因此没有业务适配 Hook 时保留本地。 */
    public function releaseLocalCopy(int $storageId, array $expected, int $now): array
    {
        $uploadConfig = $this->container->get('uploadConfig');
        if (1 !== (int)($uploadConfig['local_file'] ?? 0)) return ['state' => 'retained', 'reason' => 'configured_to_keep', 'notBefore' => 0];
        return $this->withFileProtection($storageId, function () use ($storageId, $expected, $now): array {
            $storage = $this->readOnMaster($storageId);
            if (!$storage) return ['state' => 'stale'];
            $file = $this->resolveStoredFile($storage);
            if (!$this->matchesArtifact($file['artifact'], $expected)) return ['state' => 'stale'];
            if ($file['cloudType'] !== 1) return ['state' => 'retained', 'reason' => 'cloud_not_ready', 'notBefore' => 0];
            $this->requireCloudDisk($file['diskKey']);
            $attachments = $this->container->get(AttachmentService::class);
            $after = 0;
            $seen = 0;
            $notBefore = 0;
            do {
                $references = $attachments->findStorageReferences($storageId, $after, 100);
                foreach ($references as $reference) {
                    $requirement = [];
                    $reference['storage'] = $storage;
                    // hook app_Services_Storage_FileStorageService_localCopyRequirement.php
                    if (empty($requirement['known']) || !empty($requirement['required'])) {
                        // 到期只安排原入口复核，不能将期限当成跳过其他引用的删除许可。
                        $deadline = (int)($requirement['notBefore'] ?? 0);
                        return ['state' => 'retained', 'reason' => $requirement['reason'] ?? 'unknown_reference',
                            'notBefore' => !empty($requirement['known']) && $deadline > $now ? max($notBefore, $deadline) : 0];
                    }
                    $notBefore = max($notBefore, (int)($requirement['notBefore'] ?? 0));
                    $after = (int)$reference['id'];
                    $seen++;
                }
            } while (count($references) === 100);
            if ($seen !== (int)$storage['ref_count'] || $seen === 0 || $notBefore > $now) {
                return ['state' => 'retained', 'reason' => 'references_or_outputs_not_ready', 'notBefore' => $notBefore];
            }
            $this->assertFileProtection($storageId);
            $path = $this->fsHelper->resolveStoredTarget((string)$storage['path']);
            if (!is_file($path)) return ['state' => 'already_absent'];
            if (!$this->storageManager->disk('local')->delete($path)) throw new \RuntimeException('Cannot release local file copy');
            $this->invalidateStorageCache($storageId, $storage['filehash']);
            return ['state' => 'deleted'];
        });
    }

    public function cloudReadUrls(string $diskKey, array $stableUrls, array $access): array
    {
        $this->requireCloudDisk($diskKey);
        $objectKeys = [];
        foreach ($stableUrls as $url) $objectKeys[$url] = $this->objectKeyForUrl($url);
        $mode = (string)($access['mode'] ?? '');
        if (!in_array($mode, ['public', 'restricted', 'preview', 'edit'], true)) throw new \InvalidArgumentException('Missing cloud read authorization scope');
        if ($mode !== 'public' && (!is_array($access['scope'] ?? null) || !$access['scope'])) {
            throw new \InvalidArgumentException('Missing authorized content or reuse locator');
        }
        if ($mode === 'public') {
            $prefix = (string)($this->uploadConfig['disks'][$diskKey]['url'] ?? '');
            $parsed = parse_url($prefix);
            if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment'])) {
                throw new \RuntimeException('Invalid public cloud URL prefix');
            }
            $urls = [];
            foreach ($objectKeys as $url => $key) {
                $urls[$url] = rtrim($prefix, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
            }
            return $urls;
        }
        $readUrls = [];
        // hook app_Services_Storage_FileStorageService_cloudReadUrls_private.php
        if ((int)($access['expiresAt'] ?? 0) <= time() || count($readUrls) !== count($objectKeys)) {
            throw new \RuntimeException('Private cloud read capability is unavailable');
        }
        $urls = [];
        foreach ($objectKeys as $url => $key) {
            $signed = $readUrls[$key] ?? [];
            $parsed = parse_url((string)($signed['url'] ?? ''));
            if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])
                || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['fragment'])
                || !$this->matchesCloudReadKey($diskKey, $key, $parsed)
                || (int)($signed['expiresAt'] ?? 0) <= time()
                || (int)$signed['expiresAt'] > (int)$access['expiresAt']) {
                throw new \RuntimeException('Invalid private cloud read result');
            }
            $urls[$url] = $signed['url'];
        }
        return $urls;
    }

    /** 私有签名只能指向配置的同一盘、同一对象，不接受重定向到另一对象。 */
    private function matchesCloudReadKey(string $diskKey, string $key, array $signed): bool
    {
        // 上传链路保护：已授权的私有读取仅核验同盘 signed_url，不改变稳定地址。
        $base = parse_url((string)($this->uploadConfig['disks'][$diskKey]['signed_url'] ?? ''));
        if ($base === false || ($base['scheme'] ?? '') !== 'https' || empty($base['host'])
            || isset($base['user']) || isset($base['pass']) || isset($base['query']) || isset($base['fragment'])) return false;
        $path = rtrim((string)($base['path'] ?? ''), '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        return strcasecmp((string)$base['host'], (string)$signed['host']) === 0
            && (int)($base['port'] ?? 443) === (int)($signed['port'] ?? 443)
            && (string)($signed['path'] ?? '') === $path;
    }

    /** 缓存之后、权限之后输出；不读附件表，不持久化签名 URL，也不改变正文快照。 */
    public function formatContentFiles(string $content, array $cloudState, array $access, array $fileUrls = []): array
    {
        if ((int)($cloudState['content_cloud_ready_at'] ?? 0) <= 0) return ['content' => $content, 'urls' => []];
        $diskKey = (string)($cloudState['content_cloud_disk'] ?? '');
        $attachments = $this->container->get(AttachmentService::class);
        $prefix = rtrim((string)($this->uploadConfig['disks']['local']['url'] ?? ''), '/') . '/';
        $wanted = [];
        foreach (array_merge($attachments->extractUrls($content), $fileUrls) as $url) {
            if (is_string($url) && $prefix !== '/' && strpos($url, $prefix) === 0) $wanted[$url] = true;
        }
        if (!$wanted) return ['content' => $content, 'urls' => []];
        $mapped = $this->cloudReadUrls($diskKey, array_keys($wanted), $access);
        $content = $attachments->mapFileReferences($content, function (string $url) use ($mapped): string { return $mapped[$url] ?? $url; });
        return ['content' => $content, 'urls' => $mapped];
    }

    /** 两模块共用的文件集合计算；输入均由所属业务服务核验，不查询业务表。 */
    public function contentFileManifest(array $locator, array $contents, array $associations, array $explicitIds, array $versionData, array $cloudState): array
    {
        $attachments = $this->container->get(AttachmentService::class);
        $authorized = [];
        $associationById = [];
        foreach ($associations as $association) {
            $id = (int)$association['id'];
            $associationById[$id] = $association;
            if (empty($association['storage'])) continue;
            $authorized[] = $association['storage'] + ['attachment_id' => $id, 'storage_id' => (int)$association['storage_id'], 'knownUrls' => $association['knownUrls'] ?? []];
        }
        $normalized = $attachments->normalizeFileReferences(implode("\n", $contents), $authorized);
        $used = array_fill_keys(array_merge($normalized['attachmentIds'], array_map('intval', $explicitIds)), true);
        $unknown = false;
        $files = [];
        $members = [];
        foreach ($used as $id => $unused) {
            $association = $associationById[$id] ?? [];
            if (!$association || empty($association['storage'])) { $unknown = true; continue; }
            $storage = $association['storage'];
            try {
                $file = $this->resolveStoredFile($storage);
                $file['artifactReady'] = true;
            } catch (\RuntimeException $unavailableMetadata) {
                // 历史元数据不完整不能阻断已有本地编辑，也不能被当作可信云产物。
                $file = ['storageId' => (int)$storage['id'], 'localPath' => $this->fsHelper->resolveStoredTarget((string)$storage['path']),
                    'stableUrl' => (string)$storage['url'], 'cloudType' => (int)($storage['cloud_type'] ?? 0),
                    'diskKey' => (string)($storage['disk_key'] ?? ''), 'objectKey' => (string)($storage['cloud_url'] ?? ''),
                    'artifact' => ['sha256' => '', 'size' => -1, 'mime' => (string)($storage['mime'] ?? '')], 'artifactReady' => false];
            }
            $file['isImage'] = (int)($storage['is_image'] ?? 0);
            $file['cleaned'] = (int)($storage['exif_cleaned'] ?? 0);
            $file['reviewed'] = (int)($storage['is_reviewed'] ?? 0);
            $file['width'] = (int)($storage['width'] ?? 0);
            $file['height'] = (int)($storage['height'] ?? 0);
            $files[$file['storageId']] = $file;
            $members[] = [(int)$id, $file['storageId'], (int)$association['module'],
                (int)$association['target_id'], (int)$association['reply_id']];
        }
        $knownUrls = array_fill_keys(array_column($authorized, 'url'), true);
        $prefix = rtrim((string)($this->uploadConfig['disks']['local']['url'] ?? ''), '/') . '/';
        foreach ($attachments->extractUrls($normalized['content']) as $url) {
            if ($prefix !== '/' && strpos($url, $prefix) === 0 && !isset($knownUrls[$url])) $unknown = true;
        }
        ksort($files, SORT_NUMERIC);
        sort($members);
        $artifacts = [];
        $readyDisk = null;
        $ready = !$unknown && !empty($files);
        foreach ($files as $id => $file) {
            $artifacts[] = [$id, $file['artifact']];
            if (!$file['artifactReady'] || $file['reviewed'] !== 1 || ($file['isImage'] && ($file['cleaned'] !== 1 || $file['width'] <= 0 || $file['height'] <= 0))
                || $file['cloudType'] !== 1 || $file['objectKey'] !== $this->objectKeyForUrl($file['stableUrl'])) $ready = false;
            if ($readyDisk === null) $readyDisk = $file['diskKey'];
            if ($readyDisk !== $file['diskKey']) $ready = false;
        }
        $encode = function (array $value): string {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) throw new \RuntimeException('Cannot encode content file identity');
            return hash('sha256', $json);
        };
        return [
            'state' => 'found', 'locator' => $locator,
            'versionHash' => $encode([$locator, $contents, $versionData]),
            'membershipHash' => $encode([$locator, $members]), 'artifactHash' => $encode($artifacts),
            'attachmentIds' => array_values(array_map('intval', array_keys($used))),
            'files' => $files, 'allReady' => $ready, 'filesReadyDisk' => $ready ? $readyDisk : '',
            'unknownReferences' => $unknown,
            'cloudReadyAt' => (int)($cloudState['content_cloud_ready_at'] ?? 0),
            'cloudDisk' => (string)($cloudState['content_cloud_disk'] ?? ''),
        ];
    }

    private function validDiskKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $key) === 1;
    }

    private function validObjectKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 255 || preg_match('/[\x00-\x20\x7f\\\\?#]/', $key)) return false;
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return false;
        }
        return true;
    }

    private function objectKeyForUrl(string $url): string
    {
        $prefix = rtrim((string)($this->uploadConfig['disks']['local']['url'] ?? ''), '/') . '/';
        $key = ltrim($url, '/');
        if ($prefix === '/' || strpos($url, $prefix) !== 0 || !$this->validObjectKey($key)) {
            throw new \RuntimeException('Stable file URL is not managed by this upload root');
        }
        return $key;
    }

    private function requireCloudDisk(string $diskKey): \App\Services\Storage\Interfaces\StorageInterface
    {
        if (!$this->validDiskKey($diskKey) || $diskKey === 'local' || empty($this->uploadConfig['disks'][$diskKey])) {
            throw new \RuntimeException('Cloud disk is not configured');
        }
        $disk = $this->storageManager->disk($diskKey);
        if ($disk instanceof \App\Services\Storage\Drivers\LocalStorage) {
            throw new \RuntimeException('Cloud driver is unavailable');
        }
        return $disk;
    }

    public function matchesArtifact(array $actual, array $expected): bool
    {
        return isset($expected['sha256'], $expected['size'], $expected['mime'])
            && hash_equals($actual['sha256'], (string)$expected['sha256'])
            && $actual['size'] === (int)$expected['size'] && $actual['mime'] === $expected['mime'];
    }

    public function verifyArtifactFile(string $path, array $expected): void
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        $hash = \Framework\Utils\FileHasher::sha256($path, 'hex');
        if ($size === false || $size !== (int)$expected['size'] || !hash_equals((string)$expected['sha256'], $hash)) {
            throw new \RuntimeException('Stored file artifact does not match expected bytes');
        }
    }

    private function assertFileProtection(int $storageId): void
    {
        $held = $this->getState('fileProtection', []);
        if (!isset($held[$storageId]) || $held[$storageId]['deadline'] <= microtime(true)) {
            throw new \RuntimeException('File protection expired');
        }
    }

    private function assertCloudBudget(string $diskKey, int $storageId): void
    {
        $held = $this->getState('fileProtection', []);
        $timeout = (int)($this->uploadConfig['disks'][$diskKey]['timeout'] ?? 0);
        if ($timeout <= 0 || !isset($held[$storageId]) || microtime(true) + $timeout + 5 >= $held[$storageId]['deadline']) {
            throw new \RuntimeException('Cloud operation requires a bounded timeout within file protection');
        }
    }

    /**
     * 通过文件hash获取附件（用于文件去重判断）
     * 上传保护：数据库二进制哈希/IP 必须经 read()/format() 格式化再缓存；
     * 返回值须保持 array|null，缓存键与写入后的失效键须一致。未经用户授权禁止改变此契约。
     * @param string $filehash SHA256哈希值
     * @return array|null
     */
    public function getByFilehash(string $filehash): ?array
    {
        if ($filehash === '') return null;
        $load = function () use ($filehash): array {
            $row = $this->getModel()->readFromMaster(['filehash' => $filehash]);
            if ($row) $this->format($row);
            return $row;
        };
        if (empty($this->cacheConfig['stores']) || ($this->database && $this->database->inTransaction())) return $load();
        $hex = bin2hex($filehash);
        $key = 'filehash:' . $hex;
        $data = $this->cache->cacheWithLock($key, 'lock:filehash:read:' . $hex, $load, 3, $this->ttl);
        $valid = is_array($data) && !array_diff(['id', 'filehash', 'filesize', 'path', 'url'], array_keys($data))
            && is_string($data['filehash']) && hash_equals($hex, $data['filehash']);
        if ($valid || $data === [] || $data === null) return $data;
        $this->cache->delete($key);
        $data = $load();
        if ($data) $this->cache->set($key, $data, $this->ttl);
        return $data;
    }

    /**
     * @param array $data
     */
    public function format(&$data): void{
        if (empty($data)) return;
        $i18nDateFmt = $this->i18nDateFmt;
        // hook app_Services_FileStorageService_format_start.php

        // 处理二进制哈希
        if (isset($data['filehash'])) {
            if (is_resource($data['filehash'])) {
                $data['filehash'] = stream_get_contents($data['filehash']);
            }
            $data['filehash'] = bin2hex($data['filehash']);
        }
        if (isset($data['newhash'])) {
            if (is_resource($data['newhash'])) {
                $data['newhash'] = stream_get_contents($data['newhash']);
            }
            $data['newhash'] = bin2hex($data['newhash']);
        }

        $data['create_ip'] = isset($data['create_ip']) ? \Framework\Utils\IpHelper::bin2ip($data['create_ip']) : '0.0.0.0';

        $data['created_at_fmt'] = empty($data['created_at']) ? '' : $i18nDateFmt->format((int)$data['created_at'], 'medium', 'none');
        $data['is_image_fmt'] = !empty($data['is_image']) ? '✓' : '✗';
        $data['filesize_fmt'] = $this->formatBytes(1 === (int)($data['exif_cleaned'] ?? 0) ? (int)($data['newsize'] ?? 0) : (int)($data['filesize'] ?? 0));
        // hook app_Services_FileStorageService_format_end.php
    }

    /**
     * 格式化字节大小
     * @param int $bytes 字节数
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB'];

        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);

        return round($bytes / (1 << (10 * $i)), 2) . ' ' . $units[$i];
    }

    /**
     * @param int $id
     */
    public function deleteById($id): bool
    {
        return (bool)$this->delete(['id' => $id]);
    }
}
