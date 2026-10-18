<?php

declare(strict_types=1);

namespace App\Jobs;

/** 上传链路保护：获授权按全链路方案修复，清洗准备与持久化发布阶段分离。 */
class ImageCleanupJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    protected $fileStorageService;
    protected $logger;

    public function __construct(\App\Services\Storage\FileStorageService $fileStorageService, \Framework\Logger\LoggerInterface $logger)
    {
        $this->fileStorageService = $fileStorageService;
        $this->logger = $logger;
    }

    public function handle(int $fileStorageId, string $path, array $phaseContext = []): array
    {
        if ($fileStorageId <= 0) throw new \InvalidArgumentException('Missing file storage ID');
        if (($phaseContext['phase'] ?? '') === 'clean_commit') {
            return $this->publish($fileStorageId, $phaseContext['source'], $phaseContext['prepared']);
        }
        $row = $this->fileStorageService->readOnMaster($fileStorageId);
        if (!$row) return ['status' => 'skipped', 'reason' => 'db_not_found'];
        $expected = $phaseContext['source'] ?? $this->fileStorageService->resolveStoredFile($row)['artifact'];
        $prepared = $this->prepare($fileStorageId, $expected);
        if ($prepared['state'] !== 'prepared') return ['status' => 'skipped', 'reason' => $prepared['state']];
        // 唯一云插件生产者须先可靠保存 source/prepared，再允许后继发布源文件。
        $handoff = [];
        // hook app_Jobs_ImageCleanupJob_prepared.php
        if (!\Framework\Scheduler\TaskDispatchResult::isAccepted($handoff)) {
            throw new \RuntimeException('Image cleanup publish stage has not been durably registered');
        }
        return ['status' => 'success', 'reason' => 'publish_registered', 'taskId' => \Framework\Scheduler\TaskDispatchResult::taskId($handoff)];
    }

    public function prepare(int $fileStorageId, array $expected): array
    {
        return $this->fileStorageService->withFileProtection($fileStorageId, function () use ($fileStorageId, $expected): array {
            $row = $this->fileStorageService->readOnMaster($fileStorageId);
            if (!$row) return ['state' => 'stale'];
            if (empty($row['is_image'])) return ['state' => 'not_image'];
            $file = $this->fileStorageService->resolveStoredFile($row);
            if (!$this->fileStorageService->matchesArtifact($file['artifact'], $expected)) return ['state' => 'stale'];
            if ((int)$row['exif_cleaned'] === 1) return ['state' => 'already_completed'];
            if ((int)$row['cloud_type'] !== 0) throw new \RuntimeException('Cloud image cannot be reprocessed in place');
            $path = $this->fileStorageService->resolveStoredFilePath((string)$row['path']);
            $this->fileStorageService->verifyArtifactFile($path, $expected);
            $info = getimagesize($path);
            if (!$info) throw new \RuntimeException('Cannot decode image metadata');
            if ($expected['size'] > 10485760 || (int)$info[0] * (int)$info[1] > 16777216) return ['state' => 'unsupported_size'];
            if (!extension_loaded('gd')) return ['state' => 'processor_unavailable'];
            if (!in_array($expected['mime'], ['image/jpeg', 'image/png'], true)) return ['state' => 'unsupported_format'];
            if (!$this->preservesImageSemantics($path, $expected['mime'])) return ['state' => 'unsupported_metadata_or_animation'];
            $workFile = $path . '.clean-' . $expected['sha256'];
            if (file_exists($workFile)) {
                $workFile = $this->fileStorageService->resolveStoredFilePath($workFile);
                if (!unlink($workFile)) throw new \RuntimeException('Cannot reset unpublished image work file');
            }
            $image = $expected['mime'] === 'image/png' ? imagecreatefrompng($path) : imagecreatefromjpeg($path);
            if (!$image) throw new \RuntimeException('Image cleanup decoder failed');
            $output = fopen($workFile, 'x+b');
            if (!is_resource($output)) { imagedestroy($image); throw new \RuntimeException('Cannot create image cleanup work file'); }
            try {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $written = $expected['mime'] === 'image/png' ? imagepng($image, $output, 8) : imagejpeg($image, $output, 85);
                if (!$written || !fflush($output)) throw new \RuntimeException('Image cleanup encoder failed');
            } catch (\Throwable $error) {
                fclose($output);
                imagedestroy($image);
                unlink($workFile);
                throw $error;
            }
            fclose($output);
            imagedestroy($image);
            clearstatcache(true, $workFile);
            $processed = getimagesize($workFile);
            if (!$processed || $processed['mime'] !== $expected['mime'] || $processed[0] !== $info[0] || $processed[1] !== $info[1]) {
                throw new \RuntimeException('Image cleanup output does not preserve source dimensions/type');
            }
            return ['state' => 'prepared', 'workFile' => $workFile, 'width' => (int)$processed[0], 'height' => (int)$processed[1],
                'artifact' => ['sha256' => \Framework\Utils\FileHasher::sha256($workFile, 'hex'), 'size' => filesize($workFile), 'mime' => $processed['mime']]];
        });
    }

    public function publish(int $fileStorageId, array $source, array $prepared): array
    {
        return $this->fileStorageService->withFileProtection($fileStorageId, function () use ($fileStorageId, $source, $prepared): array {
            $row = $this->fileStorageService->readOnMaster($fileStorageId);
            if (!$row) return ['status' => 'skipped', 'reason' => 'stale'];
            $file = $this->fileStorageService->resolveStoredFile($row);
            if ((int)$row['exif_cleaned'] === 1) {
                if (!$this->fileStorageService->matchesArtifact($file['artifact'], $prepared['artifact'])) throw new \RuntimeException('Image cleanup result conflicts with committed artifact');
                return ['status' => 'success', 'reason' => 'already_completed'];
            }
            if (!$this->fileStorageService->matchesArtifact($file['artifact'], $source) || (int)$row['cloud_type'] !== 0) return ['status' => 'skipped', 'reason' => 'stale'];
            $path = $this->fileStorageService->resolveStoredFilePath((string)$row['path']);
            $workFile = $path . '.clean-' . $source['sha256'];
            if (!hash_equals($workFile, (string)($prepared['workFile'] ?? ''))) throw new \RuntimeException('Invalid image work file identity');
            clearstatcache(true, $path);
            $alreadyPublished = filesize($path) === $prepared['artifact']['size']
                && hash_equals($prepared['artifact']['sha256'], \Framework\Utils\FileHasher::sha256($path, 'hex'));
            if (!$alreadyPublished) {
                $this->fileStorageService->verifyArtifactFile($path, $source);
                $workFile = $this->fileStorageService->resolveStoredFilePath($workFile);
                $this->fileStorageService->verifyArtifactFile($workFile, $prepared['artifact']);
                if (!rename($workFile, $path)) throw new \RuntimeException('Cannot publish cleaned image');
            }
            $result = $this->fileStorageService->commitCleanedArtifact($fileStorageId, $source, $prepared);
            if ($result['state'] === 'stale') throw new \RuntimeException('Image source changed during cleanup commit');
            return ['status' => 'success', 'reason' => $alreadyPublished ? 'metadata_recovered' : 'cleanup_committed'];
        });
    }

    /** 保守支持无方向 EXIF 的 JPEG 和非动画 PNG；不把扁平化动画报告为成功。 */
    private function preservesImageSemantics(string $path, string $mime): bool
    {
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) throw new \RuntimeException('Cannot inspect image format');
        try {
            if ($mime === 'image/png') {
                if (fread($stream, 8) !== "\x89PNG\r\n\x1a\n") return false;
                $hasData = false;
                $size = filesize($path);
                while (!feof($stream)) {
                    $header = fread($stream, 8);
                    if (strlen($header) !== 8) return false;
                    $length = unpack('Nlength', substr($header, 0, 4))['length'];
                    $type = substr($header, 4, 4);
                    if (in_array($type, ['acTL', 'eXIf', 'iCCP', 'gAMA', 'cHRM'], true)) return false;
                    if ($type === 'IDAT') $hasData = true;
                    if ($length > 10485760 || ftell($stream) + $length + 4 > $size) return false;
                    if ($type === 'IEND') return $hasData && $length === 0;
                    if (fseek($stream, $length + 4, SEEK_CUR) !== 0) return false;
                }
                return false;
            }
            if (fread($stream, 2) !== "\xff\xd8") return false;
            while (!feof($stream)) {
                $marker = fread($stream, 2);
                if (strlen($marker) !== 2 || $marker[0] !== "\xff") return false;
                if ($marker[1] === "\xda" || $marker[1] === "\xd9") return true;
                $lengthBytes = fread($stream, 2);
                if (strlen($lengthBytes) !== 2) return false;
                $length = unpack('nlength', $lengthBytes)['length'] - 2;
                if ($length < 0) return false;
                $prefix = fread($stream, min(6, $length));
                if ($marker[1] === "\xe1" && strpos($prefix, 'Exif') === 0) return false;
                if ($marker[1] === "\xe2" && $prefix === 'ICC_PR') return false;
                if (fseek($stream, $length - strlen($prefix), SEEK_CUR) !== 0) return false;
            }
            return false;
        } finally {
            fclose($stream);
        }
    }
}
