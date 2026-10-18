<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage;

/*
 * 上传链路保护（用户要求，2026-10-10；见根目录 AGENTS.md）：
 * 未经用户明确授权，AI 禁止修改初始化、查重、秒传、分片合并及 tmp_files 会话契约。
 * 此服务由所有插件共用；审计、重构、优化或修复测试均不构成授权，必须保留此备注。
 */
class UploadService
{
    use \Framework\Core\Traits\StatefulTrait;
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    private $cache;
    /** @var \App\Services\Storage\Support\UploadSessionStore */
    private $chunkStore;
    /** @var \Framework\Session\SessionInterface */
    private $userSession;
    /** @var \App\Services\Storage\Support\FileSystemHelper */
    public $fs;
    /** @var \App\Services\Storage\StorageManager */
    private $storage;
    /** @var \App\Services\Storage\Interfaces\StorageInterface */
    private $localDisk; // [快捷引用] 本地磁盘驱动

    /** @var \App\Services\Auth\GroupService */
    private $groupService;
    // 依赖的服务
    /** @var \App\Services\Storage\FileStorageService */
    private $fileStorageService;
    /** @var \App\Services\Storage\AttachmentService */
    private $attachmentService;
    /** @var \App\Services\Content\TempContentService */
    private $tempContentService;
    /** @var \Framework\Core\Container */
    private $container;
    /** @var array 配置对象或数组 */
    private $cfg;

    public function __construct(
        \Framework\Cache\Interfaces\CacheInterface $cache,
        \App\Services\Auth\GroupService $groupService,
        \App\Services\Storage\FileStorageService $fileStorageService,
        \App\Services\Storage\AttachmentService $attachmentService,
        \App\Services\Content\TempContentService $tempContentService,
        \App\Services\Storage\StorageManager $storage,
        array $uploadConfig,
        \Framework\Core\Container $container
    ) {
        // 获取基础依赖
        $this->cfg = $uploadConfig;
        $this->cache = $cache;

        // 业务服务
        $this->groupService = $groupService;
        $this->fileStorageService = $fileStorageService;
        $this->attachmentService = $attachmentService;
        $this->tempContentService = $tempContentService;
        $this->storage = $storage;
        $this->container = $container;

        // 初始化 Helper (保持原 Controller 逻辑)
        $this->chunkStore = new \App\Services\Storage\Support\UploadSessionStore($this->cache, (array)$this->cfg);
        $this->fs = new \App\Services\Storage\Support\FileSystemHelper((array)$this->cfg);

        // 获取本地磁盘用于初始落盘，确保高性能和可用性
        $this->localDisk = $this->storage->disk('local');
    }

    /**
     * 捕获请求上下文
     *
     * @param \Framework\Http\Interfaces\ServerRequestInterface $request
     */
    public function captureContext(\Framework\Http\Interfaces\ServerRequestInterface $request): void
    {
        $this->setState('user', $request->getAttribute('user', []));
        $this->setState('language', $request->getAttribute(\App\Interfaces\LanguageLoaderInterface::class));
        $this->setState('session', $request->getAttribute(\Framework\Session\SessionInterface::class));
        $this->setState('ip', \Framework\Utils\IpHelper::ip($request->getServerParams()));
        // 可选: 从 request param 读取 namespace
        $ns = $request->getParsedBody()['namespace'] ?? '';
        $this->setState('uploadNamespace', is_string($ns) ? $ns : '');
    }

    public function setNamespace(string $namespace): void
    {
        $this->setState('uploadNamespace', $namespace);
    }

    /**
     * 初始化上传
     * 对应原 Controller::init
     */
    public function init(array $user, string $filename, int $filesize, string $mime, string $filehash, int $preferredChunkSize, int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_init_start.php

        $filehash = strtolower(trim($filehash));
        if ($filehash !== '' && preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $filehash) !== 1) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
        }

        if (!$filename || $filesize <= 0) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filename/filesize']), 1);
        }

        // 1. 统一配额与权限检查 (包含单文件大小限制、总数、后果及单场限制)
        $quotaCheck = $this->checkUploadQuota($user, $filesize, $filename);
        if (true !== $quotaCheck) {
            throw new \App\Exception\UploadClientException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // hook app_Services_Storage_UploadService_init_before.php

        // 2. 秒传检查
        if (strlen($filehash) === 64) {
            $fastData = $this->findHash($filehash);
            if (!empty($fastData)) {
                // [关键修复] 秒传也要写会话，否则发布时将丢失该附件
                $this->saveToSession($filehash, $fastData['data'], $isAttachment);
                return ['is_fast' => true, 'data' => $fastData];
            }
        }

        // hook app_Services_Storage_UploadService_init_center.php

        // 3. 确定上传模式与分片策略
        // 模式分水岭：由 config['upload_size'] (MB) 转换字节决定
        $directThreshold = ($this->cfg['upload_size'] ?? 20) * 1024 * 1024;
        $uploadMode = ($filesize <= $directThreshold) ? 'direct' : 'chunk';

        // 分片大小限制：下限 1MB (保护服务器), 上限取分水岭与 5MB 的较小值 (性能平衡点)
        $minChunk = 1024 * 1024;
        $maxChunk = min($directThreshold, 5 * 1024 * 1024);

        $preferred = $preferredChunkSize > 0 ? $preferredChunkSize : 2 * 1024 * 1024;
        $chunkSize = max($minChunk, min($maxChunk, $preferred));

        $totalChunks = (int)ceil($filesize / $chunkSize);

        // hook app_Services_Storage_UploadService_init_middle.php

        // 4. 写入会话状态
        $meta = [
            'filename'   => $filename,
            'filesize'   => $filesize,
            'mime'       => $mime,
            'filehash'   => $filehash ?: '',
            'chunkSize'  => $chunkSize,
            'total'      => $totalChunks,
            'status'     => 'init',
            'created_at' => time(),
            'user_id' => (int)($user['id'] ?? 0),
            'session_id_hash' => $this->uploadSessionIdentity(),
        ];

        // hook app_Services_Storage_UploadService_init_after.php

        $uploadId = $this->chunkStore->create($meta);

        // hook app_Services_Storage_UploadService_init_end.php

        return ['is_fast' => false, 'data' => [
            'code' => 0,
            'status' => 'init',
            'uploadId' => $uploadId,
            'chunkSize' => $chunkSize,
            'totalChunks' => $totalChunks,
            'uploaded' => [],
            'uploadMode' => $uploadMode
        ]];
    }

    /**
     * 保存分片
     * 对应原 Controller::uploadChunk
     */
    public function saveChunk(array $user, string $uploadId, int $chunkIdx, int $chunks, \Framework\Http\Interfaces\UploadedFileInterface $file): array
    {
        // hook app_Services_Storage_UploadService_saveChunk_start.php

        // 基础参数校验
        if (!$uploadId || $chunkIdx <= 0 || $chunks <= 0) {
            throw new \App\Exception\UploadClientException('Missing uploadId/chunk/chunks', 1);
        }

        // 配额与会话检查
        // 注意：此处仅检查是否超标，不扣费。分片阶段不重复检查扩展名
        $quotaCheck = $this->checkUploadQuota($user, $file->getSize());
        if (true !== $quotaCheck) {
            throw new \App\Exception\UploadClientException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // hook app_Services_Storage_UploadService_saveChunk_before.php

        $meta = $this->chunkStore->getMeta($uploadId);
        if (!$meta) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('the_session_does_not_exist_or_has_expired'), 1);
        }

        $this->assertUploadSessionOwner($meta, $user);

        // hook app_Services_Storage_UploadService_saveChunk_center.php

        // 修正总分片数
        $total = (int)($meta['total'] ?? 0);
        if ($total > 0 && $total !== $chunks) {
            $chunks = $total;
        }

        // 1. 获取存储路径 (相对路径)
        try {
            $chunkDir = $this->fs->chunkDir($uploadId);
        } catch (\InvalidArgumentException $clientError) {
            throw new \App\Exception\UploadClientException(
                $clientError->getMessage(),
                (int)$clientError->getCode() ?: 1,
                $clientError
            );
        }
        $partPath = $chunkDir . str_pad((string)$chunkIdx, 6, '0', STR_PAD_LEFT) . '.part';

        // 幂等处理：先删后写 (操作本地磁盘)
        if ($this->localDisk->exists($partPath)) {
            $this->localDisk->delete($partPath);
        }

        // hook app_Services_Storage_UploadService_saveChunk_middle.php

        try {
            // 使用 moveTo 确保原始临时文件被清理
            $file->moveTo($partPath);
        } catch (\Throwable $e) {
            try {
                if ($this->localDisk->exists($partPath) && !$this->localDisk->delete($partPath)) {
                    throw new \RuntimeException('Partial upload chunk cleanup returned false');
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException('Failed to clean up partial upload chunk after move failure', 0, $e);
            }
            throw new \RuntimeException('Failed to move upload chunk', 0, $e);
        }

        // 安全检查 (基于已保存的文件)
        $mime = $this->localDisk->mimeType($partPath);
        if ($this->fs->blockDangerous($mime)) {
            if (!$this->localDisk->delete($partPath)) {
                throw new \RuntimeException('Failed to remove rejected upload chunk');
            }
            throw new \App\Exception\UploadClientException('Illegal shard types detected', 16);
        }

        // hook app_Services_Storage_UploadService_saveChunk_end.php

        // 更新会话
        $this->chunkStore->addPart($uploadId, $chunkIdx);

        return [
            'code' => 0,
            'message' => $this->getLang()->get('upload_successfully'),
            'part' => 'ok',
            'chunkIndex' => $chunkIdx
        ];
    }

    /**
     * 合并分片
     * 对应原 Controller::complete
     * [策略] 本地读取 -> 本地合并 -> 落盘本地 -> 用户提交 -> 关联附件表 -> 触发任务
     */
    public function complete(array $user, string $uploadId, string $filehash = '', string $tempId = '', int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_complete_start.php

        $filehash = strtolower(trim($filehash));
        if ($filehash !== '' && preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $filehash) !== 1) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
        }
        $clientSha256 = strlen($filehash) === 64 ? $filehash : '';

        // 2. 校验 Session
        $meta = $this->chunkStore->getMeta($uploadId);
        if (!$meta) throw new \App\Exception\UploadClientException($this->getLang()->get('the_session_does_not_exist_or_has_expired'), 1);

        // hook app_Services_Storage_UploadService_complete_before.php

        $filename = $meta['filename'] ?? 'file';
        $expectSize = (int)($meta['filesize'] ?? 0);
        $metaHash = strtolower(trim((string)($meta['filehash'] ?? '')));
        if ($metaHash !== '' && preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $metaHash) !== 1) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
        }
        $metaSha256 = strlen($metaHash) === 64 ? $metaHash : '';
        if ($clientSha256 !== '' && $metaSha256 !== '' && !hash_equals($metaSha256, $clientSha256)) {
            throw new \App\Exception\UploadClientException('Upload hash declaration mismatch', 1);
        }
        $declaredHash = $clientSha256 !== '' ? $clientSha256 : $metaSha256;
        $this->assertUploadSessionOwner($meta, $user);
        // 秒传也先核对本次会话与声明，随后登记 tmp_files 并收尾分片状态。
        if ($declaredHash !== '' && ($fastData = $this->findHash($declaredHash))) {
            if ((int)($fastData['data']['filesize'] ?? -1) !== $expectSize) {
                throw new \App\Exception\UploadClientException('Upload size declaration mismatch', 1);
            }
            $this->saveToSession($declaredHash, $fastData['data'], $isAttachment);
            $chunkDir = $this->fs->chunkDir($uploadId);
            if (!$this->localDisk->deleteDir($chunkDir)) throw new \RuntimeException('Cannot clean completed upload chunks');
            $this->chunkStore->destroy($uploadId);
            return ['is_fast' => true, 'data' => $fastData];
        }
        $total = (int)($meta['total'] ?? 0);
        $uploaded = $this->chunkStore->getUploadedParts($uploadId);
        if (count($uploaded) < $total) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('not_all_segments_have_been_uploaded'), 1);
        }



        try {
            $chunkDir = $this->fs->chunkDir($uploadId);
            $safeFilename = $this->fs->safeFilename($filename);
        } catch (\InvalidArgumentException $clientError) {
            throw new \App\Exception\UploadClientException(
                $clientError->getMessage(),
                (int)$clientError->getCode() ?: 1,
                $clientError
            );
        }
        try {
            $finalDir = $this->fs->dailyFinalTmpDir($this->getState('uploadNamespace', ''));
        } catch (\InvalidArgumentException $e) {
            throw new \App\Exception\UploadClientException('Invalid upload namespace', 1, $e);
        }
        $finalPath = $finalDir . $safeFilename;

        // 确保目标目录存在
        if (!is_dir(dirname($finalPath))) {
            mkdir(dirname($finalPath), 0755, true);
        }

        // 执行合并并在同一趟流读取中计算最终 SHA-256。
        // 确保目标文件不存在，避免脏数据追加
        if ($this->localDisk->exists($finalPath)) {
            $this->localDisk->delete($finalPath);
        }

        // hook app_Services_Storage_UploadService_complete_center.php

        // 3. 执行合并 (带锁)
        $lock = new \App\Services\Storage\Support\FileLock($chunkDir . '.merge.lock');
        $hashCtx = hash_init('sha256');
        $actualHash = '';

        try {
            for ($i = 1; $i <= $total; $i++) {
                $partPath = $chunkDir . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . '.part';

                // 读取分片流
                $readStream = $this->localDisk->readStream($partPath);
                if ($readStream === false) {
                    throw new \App\Exception\UploadClientException($this->getLang()->get('fragment_missing', ['fragment' => $i]), 1);
                }

                $success = $this->localDisk->appendStream($finalPath, $readStream, $hashCtx);
                fclose($readStream);
                if (!$success) {
                    throw new \RuntimeException('Failed to append chunk ' . $i);
                }

                // 可选：合并完即删分片，减少磁盘占用
                // $this->localDisk->delete($partPath);
            }

            // hook app_Services_Storage_UploadService_complete_middle.php

            // 4. 校验文件大小 (快速校验)，不计算 Hash，仅校验大小，极快
            $realSize = $this->localDisk->size($finalPath);
            if ($expectSize > 0 && $realSize !== $expectSize) {
                // 大小不一致则视为失败，删除文件
                if (!$this->localDisk->delete($finalPath)) {
                    throw new \RuntimeException('Failed to remove size-mismatched upload');
                }
                throw new \App\Exception\UploadClientException($this->getLang()->get('file_size_mismatch', ['filesize' => $expectSize, 'realSize' => $realSize]), 1);
            }

            $actualHash = hash_final($hashCtx);
            if ($declaredHash !== '' && !hash_equals($declaredHash, $actualHash)) {
                if (!$this->localDisk->delete($finalPath)) {
                    throw new \RuntimeException('Failed to remove hash-mismatched upload');
                }
                throw new \App\Exception\UploadClientException('Upload content hash mismatch', 1);
            }

            // hook app_Services_Storage_UploadService_complete_after.php

            unset($lock);
        } catch (\Throwable $e) {
            unset($lock);
            // 发生异常清理可能不完整的文件
            try {
                if ($this->localDisk->exists($finalPath) && !$this->localDisk->delete($finalPath)) {
                    throw new \RuntimeException('Incomplete merged upload cleanup returned false');
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException('Failed to clean up incomplete merged upload', 0, $e);
            }
            throw $e;
        }

        // hook app_Services_Storage_UploadService_complete_end.php

        // 8. 回执和 tmp_files 可靠登记后才消费分片，失败仍可重试本次合并。
        // 只能传递服务端在合并流中计算出的 SHA-256，禁止信任客户端声明。
        $result = $this->persistFile(
            $finalPath,
            $safeFilename,
            $filename,
            $user,
            $tempId,
            $this->getLang()->get('merge_successfully'),
            $actualHash,
            1,
            $isAttachment
        );
        if (!$this->localDisk->deleteDir($chunkDir)) throw new \RuntimeException('Cannot clean completed upload chunks');
        $this->chunkStore->destroy($uploadId);
        return $result;
    }

    /**
     * 小文件直传
     * 对应原 Controller::direct
     * [策略] 流式Hash -> 落盘本地 -> 用户提交 -> 关联附件表 -> 触发任务
     */
    public function direct(array $user, \Framework\Http\Interfaces\UploadedFileInterface $file, string $filename, int $filesize, string $filehash, string $tempId = '', int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_direct_start.php

        $filehash = strtolower(trim($filehash));
        if ($filehash !== '' && preg_match('/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/', $filehash) !== 1) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
        }

        // 1. 配额检查
        $quotaCheck = $this->checkUploadQuota($user, $filesize, $filename, $tempId);
        if (true !== $quotaCheck) {
            throw new \App\Exception\UploadClientException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        // 校验
        if ($filesize <= 0) throw new \App\Exception\UploadClientException($this->getLang()->get('parameter_error', ['error' => 'filesize']), 1);
        if ($filesize > $this->cfg['max_file_size']) {
            throw new \App\Exception\UploadClientException($this->getLang()->get('filesize_too_large', [
                'maxsize' => round($this->cfg['max_file_size'] / 1024 / 1024, 2) . 'MB',
                'size' => round($filesize / 1024 / 1024, 2) . 'MB'
            ]), 1);
        }

        // hook app_Services_Storage_UploadService_direct_before.php

        // 秒传检查
        if (strlen($filehash) === 64 && ($fastData = $this->findHash($filehash))) {
            $this->saveToSession($filehash, $fastData['data'], $isAttachment);
            return ['is_fast' => true, 'data' => $fastData];
        }

        // 获取 PSR-7 输入流并计算 Hash
        $srcStream = $file->getStream();
        $hashCtx = hash_init('sha256');

        // 分块读取流，避免大文件内存溢出
        if ($srcStream->isSeekable()) $srcStream->rewind();
        while (!$srcStream->eof()) {
            $buf = $srcStream->read(8192);
            if ($buf === '') break;
            hash_update($hashCtx, $buf);
        }
        if ($srcStream->isSeekable()) $srcStream->rewind();

        $filehashRaw = hash_final($hashCtx, true);
        $filehashHex = bin2hex($filehashRaw);

        // 查重
        $fastData = $this->findBbHash($filehashRaw, $filehashHex);

        if (!empty($fastData)) {
            // 如果是秒传，手动清理 PSR-7 临时文件
            $uri = (string)$srcStream->getMetadata('uri');
            if ($uri && is_file($uri)) @unlink($uri);

            $this->saveToSession($filehashHex, $fastData['data'], $isAttachment);
            return ['is_fast' => true, 'data' => $fastData];
        }

        // 落盘到正式临时目录，使用 moveTo 彻底清理原始临时文件
        try {
            $safeFilename = $this->fs->safeFilename($filename);
        } catch (\InvalidArgumentException $clientError) {
            throw new \App\Exception\UploadClientException(
                $clientError->getMessage(),
                (int)$clientError->getCode() ?: 1,
                $clientError
            );
        }
        try {
            $finalDir = $this->fs->dailyFinalTmpDir($this->getState('uploadNamespace', ''));
        } catch (\InvalidArgumentException $e) {
            throw new \App\Exception\UploadClientException('Invalid upload namespace', 1, $e);
        }
        $finalPath = $finalDir . $safeFilename;

        try {
            $file->moveTo($finalPath);
        } catch (\Throwable $e) {
            try {
                if ($this->localDisk->exists($finalPath) && !$this->localDisk->delete($finalPath)) {
                    throw new \RuntimeException('Partial direct upload cleanup returned false');
                }
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException('Failed to clean up partial direct upload', 0, $e);
            }
            throw new \RuntimeException($this->getLang()->get('upload_failed'), 0, $e);
        }

        // 持久化 (入库、扣费)
        return $this->persistFile(
            $finalPath,
            $safeFilename,
            $filename,
            $user,
            $tempId,
            $this->getLang()->get('upload_successfully'),
            $filehashHex,
            0,
            $isAttachment
        );
    }

    /**
     * 核心：文件持久化、查重、入库、扣费
     * 整合了原 processFilePersistence 逻辑
     */
    private function persistFile(string $finalPath, string $safeFilename, string $orgFilename, array $user, string $tempId, string $successMsg, ?string $preCalcHashHex = null, int $largefiles = 0, int $isAttachment = 0): array
    {
        // hook app_Services_Storage_UploadService_persistFile_start.php

        // 1. 安全检查 (在本地磁盘上进行)
        $realMime = $this->localDisk->mimeType($finalPath);
        if ($this->isDangerousFile($realMime ?: '', $orgFilename)) {
            if (!$this->localDisk->delete($finalPath)) {
                throw new \RuntimeException('Failed to remove rejected upload');
            }
            throw new \App\Exception\UploadClientException($this->getLang()->get('illegal_file_type', ['name' => 'PHP']), 16);
        }

        $realSize = $this->localDisk->size($finalPath);

        // 生成本地 URL (因为此时文件还在本地)
        list($path, $url) = $this->fs->fileUrl($finalPath);

        // hook app_Services_Storage_UploadService_persistFile_before.php

        // 2. Hash 计算与 DB 查重
        $filehashIndex = $preCalcHashHex;
        $filehash32 = null;

        // 从本地磁盘读取补算
        if (null === $filehashIndex) {
            $stream = $this->localDisk->readStream($finalPath);
            if ($stream) {
                $ctx = hash_init('sha256');
                while (!feof($stream)) hash_update($ctx, fread($stream, 8192));
                fclose($stream);
                $filehashRaw = hash_final($ctx, true);
                $filehash32 = $filehashRaw;
                $filehashIndex = bin2hex($filehashRaw); // 将二进制字符串转换成十六进制字符串
            }

            // hook app_Services_Storage_UploadService_persistFile_center.php

            $fastData = $this->findBbHash($filehash32, $filehashIndex);
            if (!empty($fastData)) {
                $this->localDisk->delete($finalPath);
                $this->saveToSession($filehashIndex, $fastData['data'], $isAttachment);
                return ['is_fast' => true, 'data' => $fastData];
            }
        } else {
            // 预计算值也必须是服务端产生的完整 SHA-256。
            $filehashIndex = strtolower(trim((string)$filehashIndex));
            if (preg_match('/^[a-f0-9]{64}$/', $filehashIndex) !== 1) {
                throw new \RuntimeException($this->getLang()->get('parameter_error', ['error' => 'filehash']), 1);
            }
            $filehash32 = hex2bin($filehashIndex);
        }

        // hook app_Services_Storage_UploadService_persistFile_middle.php

        // 扣除配额，只有文件是新的（非秒传）且已落盘
        // 最终状态核实：在入库前再次确认单场文件限制，彻底封堵高并发绕过风险
        $quotaCheck = $this->checkUploadQuota($user, (int)$realSize, $orgFilename, $tempId);
        if (true !== $quotaCheck) {
            if (!$this->localDisk->delete($finalPath)) {
                throw new \RuntimeException('Failed to remove quota-rejected upload');
            }
            throw new \App\Exception\UploadClientException($quotaCheck['msg'] ?? 'Upload quota exceeded', $quotaCheck['code'] ?? 1);
        }

        $this->recordUploadStats((int)($user['id'] ?? 0), $realSize);

        // 3. 准备数据
        $isImage = $this->fs->isImage($finalPath, $this->cfg['allowed_image_mimes']) ? 1 : 0;
        // 审核分流：html/js（MIME 或扩展名命中）→ 标记待审核，审核通过前仅所有者与管理员可下载
        $needsReview = $this->needsReview($realMime ?: '', $orgFilename);
        $ip = \Framework\Utils\IpHelper::ip();

        $data = [
            'filehash' => $filehash32,
            'filesize' => $realSize,
            'width' => 0,
            'height' => 0,
            'is_reviewed' => $needsReview ? 0 : 1,
            'reviewed_at' => $needsReview ? 0 : time(),
            'reviewed_by' => $needsReview ? 0 : 1,
            'exif_cleaned' => 0,
            'is_image' => $isImage,
            'create_ip' => $ip,
            'created_at' => time(),
            'filename' => $safeFilename,
            'orgfilename' => basename($orgFilename),
            'mime' => $realMime ?: 'application/octet-stream',
            'path' => $path,
            'url' => $url,
            'exif_data' => '',
            'target_id' => 0,
            'reply_id' => 0,
            'module' => 0,
            'user_id' => (int)($user['id'] ?? 0),
            'large_files' => $largefiles, // 大文件，提交后需推送定时任务重新计算hash和size
            'is_attachment' => $isAttachment
        ];

        // 4. 写入 Cache 索引 (秒传用)
        $this->saveToCacheIndex($filehashIndex, $data);

        // 5. 记录元数据到 Session (不直接入库，待发布或存稿时关联)
        $this->saveToSession($filehashIndex, $data, $isAttachment);

        // hook app_Services_Storage_UploadService_persistFile_after.php

        // Scheduler 异步任务触发
        // 业务流：上传 -> Temp表（文件在upload_temp目录） -> 用户提交 -> 比对图片数据 -> 从 upload/temp/202601/ 目录复制到 upload/202601/ 目录 -> 写入 file_storage 表 & attachment 表，生成一个 file_storage.id -> 删除upload/temp/202601/文件和目录（判断目录下是否还有文件）
        // 业务逻辑“用户提交表单后才正式入库”，则任务触发可能需要移到 AttachmentService 的 post logic
        // TODO: 1. 大文件触发异步完整性校验 (Hash & Size)
        /* if ($this->cacheConfig['default'] === 'redis' && (int)($data['large_files'] ?? 0) > 0) {
            $taskManage = $this->container->has(\Framework\Scheduler\TaskManage::class)
                ? $this->container->get(\Framework\Scheduler\TaskManage::class)
                : null;

            if ($taskManage) {
                // 推送异步校验任务
                $taskManage->createTask([
                    'className' => \App\Jobs\VerifyIntegrityJob::class,
                    'methodName' => 'handle',
                    'args' => [
                        'fileStorageId' => $fileStorageId
                    ],
                    'priority' => 9, // 设置为低优先级，不阻塞核心业务
                    'retryDelay' => 30,
                    'maxRetries' => 3
                ]);
            }
        } */

        // TODO: 2. 触发异步清洗任务，应该判断是否启用redis和Scheduler，如果未启用则跳过清洗
        /* if ($this->cacheConfig['default'] === 'redis' && $isImage && !empty($this->cfg['clean_exif'])) {
            // Temp 阶段不清洗
            // 获取 TaskManage 实例（包含是否启用检查）
            $scheduler = $this->getTaskManage();
            if ($scheduler) {
                // 推送异步清洗任务
                try {
                    $scheduler->createTask([
                        'className' => \App\Jobs\ImageCleanupJob::class,
                        'methodName' => 'handle',
                        'args' => [
                            'fileStorageId' => $fileStorageId,
                            'path' => $finalPath
                        ],
                        'priority' => 5,
                        'scheduledAt' => time() + 20, // 间隔20秒处理
                        'retryDelay' => 30,
                        'maxRetries' => 3
                    ]);
                } catch (\Throwable $e) {
                    // 容错：推送任务失败不应阻断上传流程，改为降级处理或仅记录日志
                    $this->container->get(LoggerInterface::class)->error('Failed to push cleanup task', ['error' => $e->getMessage()]);
                }
            }
        } */

        // TODO: 3. 提交清洗后执行云储存 UploadToCloudJob 任务
        /* if ($this->cacheConfig['default'] === 'redis') {
            $taskManage = $this->container->has(\Framework\Scheduler\TaskManage::class)
                ? $this->container->get(\Framework\Scheduler\TaskManage::class)
                : null;

            if ($taskManage) {
                // 推送异步校验任务
                $taskManage->createTask([
                    'className' => 'App\\Jobs\\UploadToCloudJob',
                    'methodName' => 'handle',
                    'args' => [
                        'fileStorageId' => $fileStorageId,
                        'cfg' => $uploadConfig
                    ],
                    'priority' => 5,
                    'scheduledAt' => time() + 40, // 间隔20秒处理
                    'retryDelay' => 30,
                    'maxRetries' => 3
                ]);
            }
        } */

        $stored = new \Framework\Http\Psr7\StoredFile($finalPath, $safeFilename, (string)$data['mime'], (int)$realSize);

        // hook app_Services_Storage_UploadService_persistFile_end.php

        return ['is_fast' => false, 'data' => [
            'code' => 0,
            'message' => $successMsg,
            'status' => 'complete',
            'file' => [
                'name' => $orgFilename,
                'size' => \Framework\Utils\FormatHelper::humanSize((int)$realSize),
                'url'  => $url,
                'mime' => $data['mime'],
                'hash' => $filehashIndex
            ]
        ]];
    }

    /**
     * 将临时文件记录到 Session
     */
    private function saveToSession(string $hash, array $data, int $isAttachment = 0): void
    {
        $key = 'tmp_files';
        $session = $this->getState('session');
        if ($session instanceof \Framework\Session\SessionInterface) {
            $tmpFiles = $session->get($key, []);

            // 确保数据中不含非 UTF-8 字符（如二进制 Hash），防止 PostgreSQL 写入报错
            $data = $this->sanitizeMetadataForSession($data);

            $data['is_attachment'] = $isAttachment;
            $tmpFiles[$hash] = $data;
            $session->set($key, $tmpFiles);
        }
    }

    /**
     * 清洗元数据，确保所有二进制字段转为 Hex 字符串
     */
    public function sanitizeMetadataForSession(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeMetadataForSession($value);
                continue;
            }
            if (!is_string($value)) continue;
            if (in_array($key, ['filehash', 'newhash'], true) && strlen($value) === 32) {
                $data[$key] = bin2hex($value);
            } elseif ($key === 'create_ip' && !filter_var($value, FILTER_VALIDATE_IP)
                && in_array(strlen($value), [4, 16], true)) {
                $data[$key] = \Framework\Utils\IpHelper::bin2ip($value);
            }
        }
        return $data;
    }

    /**
     * 恢复元数据，将 Hex 字符串转回二进制
     */
    public function restoreMetadata(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->restoreMetadata($v);
            } elseif (is_string($v)) {
                // 如果长度为 64 的 Hex 字符串且 Key 是 filehash，恢复为 32 字节二进制
                if (in_array($k, ['filehash', 'newhash'], true) && strlen($v) === 64 && ctype_xdigit($v)) {
                    $data[$k] = hex2bin($v);
                }
            }
        }
        return $data;
    }

    /** 仅从当前上传会话补回遗漏的文件，不按正文中的任意路径扫描服务器。 */
    public function sessionFileForUrl(string $url): array
    {
        $session = $this->getState('session');
        if (!$session instanceof \Framework\Session\SessionInterface) return [];
        $files = $session->get('tmp_files', []);
        if (!is_array($files)) throw new \RuntimeException('Invalid temporary upload session');
        foreach ($files as $file) {
            if (is_array($file) && isset($file['url']) && hash_equals((string)$file['url'], $url)) {
                return $this->restoreMetadata($file);
            }
        }
        return [];
    }

    private function uploadSessionIdentity(): string
    {
        $session = $this->getState('session');
        if (!$session instanceof \Framework\Session\SessionInterface || $session->getId() === '') {
            throw new \RuntimeException('Upload session is unavailable');
        }
        return hash('sha256', $session->getId());
    }

    private function assertUploadSessionOwner(array $meta, array $user): void
    {
        if (!isset($meta['user_id'], $meta['session_id_hash'])
            || (int)$meta['user_id'] !== (int)($user['id'] ?? 0)
            || !hash_equals((string)$meta['session_id_hash'], $this->uploadSessionIdentity())) {
            throw new \App\Exception\UploadClientException('Upload session ownership mismatch', 1);
        }
    }

    /**
     * 根据 Hash 删除临时文件并清理 Session
     */
    public function deleteTempByHash(string $hash): bool
    {
        $key = 'tmp_files';
        $session = $this->getState('session');
        if ($session instanceof \Framework\Session\SessionInterface) {
            $tmpFiles = $session->get($key, []);
            if (isset($tmpFiles[$hash])) {
                $path = (string)($tmpFiles[$hash]['path'] ?? '');
                if ($path !== '') {
                    try {
                        $fullPath = $this->fs->resolveManagedTempFile($path);
                        @unlink($fullPath);
                    } catch (\Throwable $e) {
                        // 根外、符号链接或已不存在：只清理当前会话项，绝不扩大删除边界。
                    }
                }
                unset($tmpFiles[$hash]);
                $session->set($key, $tmpFiles);
                return true;
            }
        }
        return false;
    }

    // 查缓存和DB
    public function findHash( string $filehash= ''): array
    {
        if ($filehash) {
            $filehash = \App\Services\Storage\Support\Util::normalizeHash($filehash);
            // 32 位旧客户端值是 filename+filesize 的 MD5 兼容提示，不是内容身份。
            if ($filehash && strlen($filehash) === 64) {
                // 查 Cache 索引
                $key = "hash:index:{$filehash}";
                $hit = $this->cache->get($key); // 对应原 findByHash

                if (empty($hit) || empty($hit['path'])) {
                    // 查库
                    $filehash32 = @hex2bin($filehash);
                    if ($filehash32 === false) return [];
                    $hit = $this->fileStorageService->getByFilehash($filehash32);
                }

                if (is_array($hit) && empty($hit['id'])) {
                    $owned = $this->sessionFileForUrl((string)($hit['url'] ?? ''));
                    if (!$owned || (string)$owned['path'] !== (string)($hit['path'] ?? '')) {
                        $this->cache->delete($key);
                        $hit = $this->fileStorageService->getByFilehash($filehash32 ?? hex2bin($filehash));
                    } else {
                        $hit = $owned;
                    }
                }
                if (is_array($hit) && !empty($hit['id'])) {
                    $hit = $this->fileStorageService->readOnMaster((int)$hit['id']);
                    if (!$hit || !hash_equals((string)($hit['filehash'] ?? ''), $filehash)) {
                        $this->cache->delete($key);
                        return [];
                    }
                }
                $previewUrl = '';
                // 上传保护：数据库 path 是受管定位值，先用既有边界解析器转为绝对路径；
                // 不把 /storage/upload/... 直接交给 LocalStorage 当成系统根路径。
                $available = false;
                if (is_array($hit) && !empty($hit['path'])) {
                    try {
                        $localPath = !empty($hit['id'])
                            ? $this->fileStorageService->resolveStoredFilePath((string)$hit['path'])
                            : $this->fileStorageService->resolveTempFilePath((string)$hit['path']);
                        $available = $this->localDisk->exists($localPath);
                    } catch (\RuntimeException $unavailableLocalFile) {
                        // 缺失或无效本地定位仍交给原云端复用检查，不宣称秒传成功。
                    }
                }
                if (!$available && is_array($hit) && !empty($hit['id']) && (int)($hit['cloud_type'] ?? 0) === 1) {
                    $user = $this->getState('user', []);
                    $userId = (int)($user['id'] ?? 0);
                    $diskKey = (string)($hit['disk_key'] ?? '');
                    $owned = $userId > 0 && ((int)($user['group_id'] ?? 0) === 1
                        || $this->attachmentService->findOnMaster(['storage_id' => (int)$hit['id'], 'user_id' => $userId], 0, 1));
                    if ($owned && $diskKey !== '' && $diskKey !== 'local'
                        && !($this->storage->disk($diskKey) instanceof \App\Services\Storage\Drivers\LocalStorage)) {
                        $this->fileStorageService->resolveStoredFile($hit);
                        $urls = $this->fileStorageService->cloudReadUrls($diskKey, [(string)$hit['url']], ['mode' => 'edit',
                            'scope' => ['module' => 'storage', 'kind' => 'reuse', 'storageId' => (int)$hit['id'], 'actorId' => $userId],
                            'expiresAt' => time() + 300]);
                        $previewUrl = $urls[(string)$hit['url']];
                        $available = true;
                    }
                }
                if ($available) {
                    // 同步 Cache 索引
                    $this->saveToCacheIndex($filehash, $hit);

                    // [关键修复] 清洗元数据防止二进制字符进入 Session
                    $sanitizedHit = $this->sanitizeMetadataForSession($hit);
                    return [
                        'code' => 0,
                        'message' => $this->getLang()->get('upload_successfully'),
                        'status' => 'complete',
                        'data' => $sanitizedHit,
                        'file' => [
                            'name' => isset($hit['orgfilename']) ? $hit['orgfilename'] : (isset($hit['filename']) ? $hit['filename'] : basename($hit['path'])),
                            'size' => isset($hit['filesize']) ? (int)$hit['filesize'] : 0,
                            'url'  => isset($hit['url']) ? $hit['url'] : '',
                            'mime' => isset($hit['mime']) ? $hit['mime'] : 'application/octet-stream',
                            'hash' => $filehash,
                            'previewUrl' => $previewUrl,
                        ]
                    ];
                }
            }
        }
        return [];
    }

    private function findBbHash(string $filehash32, string $filehashIndex): array
    {
        if (!hash_equals(bin2hex($filehash32), $filehashIndex)) {
            throw new \InvalidArgumentException('Upload hash representation mismatch');
        }
        return $this->findHash($filehashIndex);
    }

    private function saveToCacheIndex(string $filehash, array $meta): void{
        if (!$filehash) return;
        $key = "hash:index:{$filehash}";
        // 关键：在存入 Cache/Redis 前，必须清洗二进制数据（如 filehash 二进制串），否则 json_encode 会失败
        $meta = $this->sanitizeMetadataForSession($meta);
        $this->cache->set($key, $meta); // 长期索引
    }

    /**
     * 检查配额 (只读，不扣费)
     * 补齐方案：增加单场数量限制和基于数字ID的后缀权限控制
     * @return bool
     */
    private function checkUploadQuota(array $user, int $filesize, string $filename = '', string $tempId = '')
    {
        $userId = (int)($user['id'] ?? 0);
        $groupId = (int)($user['group_id'] ?? 0);

        // 1. 管理员策略：保留最高权限，仅受物理配置限制
        if ($groupId === 1) return true;

        // 2. 获取用户组实时动态配置 (由 GroupService 实现缓存)
        $group = $this->groupService->read($groupId);
        if (empty($group)) {
            return ['msg' => $this->getLang()->get('user_group_insufficient_privileges'), 'code' => 15];
        }

        // 3. 基础权限检查
        if (empty($group['upload'])) {
            return ['msg' => $this->getLang()->get('user_group_insufficient_privileges'), 'code' => 15];
        }

        // 4. 解析配额指标 (DB 字段优先，若为 0 则降级到全局 Config)
        $limitSingle = (int)($group['quota_single_size'] ?: ($this->cfg['limit_defaults']['quota_single_size_default'] ?? ($this->cfg['max_file_size'] ?? 0)));
        $limitDailySize = (int)($group['quota_daily_size'] ?: ($this->cfg['limit_defaults']['quota_daily_size_default'] ?? 0));
        $limitDailyCount = (int)($group['upload_daily_quota'] ?: ($this->cfg['limit_defaults']['upload_daily_quota'] ?? 50));
        $limitPerPost = (int)($group['upload_per_post'] ?: ($this->cfg['limit_defaults']['upload_per_post'] ?? 10));

        // 5. 后缀权限校验 (基于数字 ID 重排)
        if ($filename !== '') {
            $allowedId = (int)($group['allowed_file_types'] ?? 0);
            $presetList = [];
            // 如果 ID 为 0，FileSystemHelper 会自动 fallback 到全局 allowed_ext
            if ($allowedId > 0 && !empty($this->cfg['type_presets'][$allowedId])) {
                $presetList = $this->cfg['type_presets'][$allowedId];
            }

            if (!$this->fs->allowedExt($filename, $presetList)) {
                return ['msg' => $this->getLang()->get('illegal_file_type', ['name' => pathinfo($filename, PATHINFO_EXTENSION)]), 'code' => 16];
            }
        }

        // 6. 单文件大小拦截
        if ($limitSingle > 0 && $filesize > $limitSingle) {
            return [
                'msg' => $this->getLang()->get('file_size_mismatch', [
                    'filesize' => round($filesize / 1024 / 1024, 2) . 'MB',
                    'realSize' => round($limitSingle / 1024 / 1024, 2) . 'MB'
                ]),
                'code' => 1
            ];
        }

        // 7. 单场发布数量限制 (upload_per_post)
        // 核心点：检查 Session 或已有 TempContent 中的文件数
        $currentTempCount = 0;
        $key = 'tmp_files';
        if ($this->userSession) {
            $currentTempCount = count($this->userSession->get($key, []));
        }

        if ($tempId) {
            $tempContent = $this->tempContentService->read($tempId);
            if ($tempContent && !empty($tempContent['data_fmt'][$key])) {
                $currentTempCount = max($currentTempCount, count($tempContent['data_fmt'][$key]));
            }
        }

        if ($limitPerPost > 0 && ($currentTempCount + 1) > $limitPerPost) {
            return ['msg' => $this->getLang()->get('upload_limit_exceeded', ['n' => $limitPerPost]), 'code' => 15];
        }

        // 8. 每日累计指标检查 (基于原子锁的读取与判定，防止高并发绕过)
        $lockKey = "lock:upload_quota:{$userId}";
        $todayKey = "user_upload_today:{$userId}:" . date('Ymd');

        // 为了保持逻辑内聚且严谨，防止高并发绕过，对于“配额判定”采取手动原子锁闭环。
        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) {
            return ['msg' => $this->getLang()->get('server_busy_try_later'), 'code' => 503];
        }

        try {
            $todayStats = $this->cache->get($todayKey) ?: ['files' => 0, 'size' => 0];

            // 数量限制判断
            if ($limitDailyCount > 0 && $todayStats['files'] >= $limitDailyCount) {
                return ['msg' => $this->getLang()->get('daily_files_limit', ['daily_files' => $limitDailyCount]), 'code' => 15];
            }

            // 容量限制判断
            if ($limitDailySize > 0 && ($todayStats['size'] + $filesize) > $limitDailySize) {
                $remainingMb = round(max(0, $limitDailySize - $todayStats['size']) / 1024 / 1024, 2);
                return [
                    'msg' => $this->getLang()->get('daily_storage_limit_reached', ['remaining' => $remainingMb]),
                    'code' => 1
                ];
            }

            return true;
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }

    /**
     * 硬拦截判定：可执行文件 / 可执行代码 / 内容与扩展名类型混淆。
     *
     * 分层策略：
     *   1. MIME 硬拦截：可执行代码与 Windows 可执行（代码层安全底线，不依赖配置）
     *   2. 扩展名硬黑名单：finfo 识别失败或降级时的纵深防御
     *   3. 内容-白名单一致性：强特征 MIME（有魔数/容器结构的二进制）必须存在于
     *      allowed_mimes 白名单，防止"白名单扩展名 + 异形可执行内容"类型混淆。
     *      svg（image/svg+xml）已在白名单移除，即使扩展名白名单被误改，内容层仍会拦截。
     *
     * 注意：html/js 不在拦截范围 —— 它们有正当附件业务且可安全分发（nginx deny 静态伺服
     * + download 接口审核门禁），由 needsReview() 标记待审。
     *
     * @param string $mimetype finfo 内容检测的 MIME
     * @param string $filename 原始文件名
     * @return bool true=必须拒绝上传
     */
    public function isDangerousFile(string $mimetype, string $filename): bool
    {
        // 1. MIME 硬拦截（可执行代码 / Windows 可执行）
        //    注意：libmagic 对 PHP 文件的识别结果同时有 application/x-php 与 text/x-php
        //    （实测 finfo），两种都必须拦截，否则伪装图片扩展名即可绕过。
        $blockMimes = [
            'application/x-msdownload', // exe/dll/com/bat/msi
            'application/x-sh',         // sh
            'application/x-bat',        // bat
            'application/x-php',        // php
            'text/x-php',               // php（libmagic 变体）
        ];
        if (in_array($mimetype, $blockMimes, true)) {
            return true;
        }

        // 2. 扩展名硬黑名单（纵深防御：finfo 不可用/识别失败时兜底）
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $dangerousExts = ['exe', 'bat', 'sh', 'php', 'php3', 'php5', 'phtml', 'phar'];
        if (in_array($ext, $dangerousExts, true)) {
            return true;
        }

        // 3. 内容-扩展名一致性：强特征 MIME 必须命中 allowed_mimes 白名单
        if ($this->isStrongMimeNotAllowed($mimetype)) {
            return true;
        }

        return false;
    }

    /**
     * 待审判定：标记为待审核（is_reviewed=0），审核通过（is_reviewed=1）
     * 前仅文件所有者与管理员可下载。
     *
     * 判定为 MIME 或扩展名任一命中：
     *   - MIME：require_review 配置（text/html、text/javascript 等）
     *   - 扩展名：html/htm/js —— 实测 libmagic 对简单 JS 内容识别为 text/plain，
     *     仅查 MIME 会被绕过，扩展名判定兜底。
     *
     * @param string $mimetype finfo 内容检测的 MIME
     * @param string $filename 原始文件名
     * @return bool true=需要人工审核
     */
    public function needsReview(string $mimetype, string $filename): bool
    {
        $requireReview = $this->cfg['require_review'] ?? [
            'text/html',             // html（可内嵌脚本）
            'text/javascript',       // js（finfo 两种识别结果都要覆盖）
            'application/javascript',
        ];
        if (in_array($mimetype, $requireReview, true)) {
            return true;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm', 'js'], true)) {
            return true;
        }

        return false;
    }

    /**
     * 强特征 MIME 白名单校验。
     *
     * 文本类（text/*、application/json、application/javascript）无魔数，
     * finfo 按内容特征识别，不参与一致性校验；其余类型（image/*、video/*、
     * application/pdf 等）必须有文件签名，内容 MIME 必须存在于 allowed_mimes。
     *
     * @param string $mimetype finfo 内容检测的 MIME
     * @return bool true=强特征类型但不在白名单（类型混淆，应拒绝）
     */
    private function isStrongMimeNotAllowed(string $mimetype): bool
    {
        // 无法检测/无特征：交给扩展名白名单与黑名单兜底
        if ($mimetype === '' || $mimetype === 'application/octet-stream') {
            return false;
        }

        // 弱特征文本类：无文件签名，不参与强校验（txt/html/js/json/css 等）
        $weakPrefixes = ['text/', 'application/json', 'application/javascript'];
        foreach ($weakPrefixes as $prefix) {
            if (strpos($mimetype, $prefix) === 0) {
                return false;
            }
        }

        // 强特征类型：内容 MIME 必须命中 allowed_mimes 白名单
        $allowedMimes = $this->cfg['allowed_mimes'] ?? [];
        foreach ($allowedMimes as $mime => $exts) {
            if ($mime === $mimetype) {
                return false;
            }
        }
        return true;
    }

    protected function getLang(): \App\Interfaces\LanguageLoaderInterface
    {
        return $this->getState('language') ?? $this->container->get(\App\Interfaces\LanguageLoaderInterface::class);
    }

    /**
     * 记录统计 (扣费) - 引入原子锁防止统计丢失
     */
    private function recordUploadStats(int $userId, int $filesize): void
    {
        $lockKey = "lock:upload_quota:{$userId}";
        $todayKey = "user_upload_today:{$userId}:" . date('Ymd');

        $token = $this->cache->lock($lockKey, 5) ?? null;
        if (!$token) return; // 容错：即使没抢到锁也继续（因为持久化已完成），避免阻断用户，但会有极端概率统计偏差

        try {
            $todayStats = $this->cache->get($todayKey) ?: ['files' => 0, 'size' => 0];
            $todayStats['files']++;
            $todayStats['size'] += $filesize;

            $tomorrow4am = strtotime('tomorrow 4:00') - time();
            $this->cache->set($todayKey, $todayStats, $tomorrow4am);
        } finally {
            $this->cache->unlock($lockKey, $token);
        }
    }
}
