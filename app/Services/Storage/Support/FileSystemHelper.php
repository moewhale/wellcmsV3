<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Services\Storage\Support;

/*
 * 上传链路保护（用户要求，2026-10-10；见根目录 AGENTS.md）：
 * 未经用户明确授权，AI 禁止修改上传路径、URL、文件校验及临时/正式目录处理行为。
 * 审计、重构、优化或修复测试均不构成授权，必须保留此备注。
 */
/** -----------------------------
 * 抽象：文件系统工具
 * - 分片目录、最终目录
 * - URL 生成
 * - 安全检查/MIME
 * ----------------------------- */
class FileSystemHelper
{
    /** @var array */
    private $cfg;
    /** @var array */
    private $allowedExt;

    public function __construct(array $cfg, array $allowedExt = [])
    {
        $this->cfg = $cfg;
        // 预创建根目录与临时目录，确保环境可用
        $root = $this->normalizePath($cfg['disks']['local']['root'] ?? 'storage/upload/');
        $temp = $this->normalizePath($cfg['upload_temp'] ?? 'storage/tmp/');

        $this->ensureDir($root);
        $this->ensureDir($temp);
        $this->allowedExt = $allowedExt;
    }

    /**
     * @param string $path
     * @return string
     */
    public function normalizePath($path): string
    {
        if (empty($path)) return '';
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $appPath = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, APP_PATH), DIRECTORY_SEPARATOR);

        if ($path === $appPath) return $appPath . DIRECTORY_SEPARATOR;
        if (strpos($path, $appPath . DIRECTORY_SEPARATOR) === 0) {
            return $path;
        }
        return $appPath . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR . '\\');
    }

    /**
     * 将路径解析为上传临时根中的普通文件。
     */
    public function resolveManagedTempFile(string $path): string
    {
        return $this->resolveRegularFileWithinRoot($path, (string)$this->cfg['upload_temp'], 'upload temp');
    }

    /**
     * 将路径解析为正式存储根中的普通文件。
     */
    public function resolveStoredFile(string $path): string
    {
        return $this->resolveRegularFileWithinRoot($path, (string)$this->cfg['disks']['local']['root'], 'upload storage');
    }

    /** 恢复写入独立校验：目标可以不存在，受管根与现存父目录不得是符号链接。 */
    public function resolveStoredTarget(string $path, bool $createParents = false): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new \RuntimeException('Invalid upload storage target');
        }
        $root = $this->normalizePath((string)$this->cfg['disks']['local']['root']);
        $realRoot = realpath($root);
        if ($realRoot === false || is_link(rtrim($root, DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('Invalid upload storage root');
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $target = $this->normalizePath($path);
        if (strpos($target, $root) !== 0) {
            throw new \RuntimeException('Upload storage target is outside configured root');
        }
        $parts = explode(DIRECTORY_SEPARATOR, substr($target, strlen($root)));
        $current = rtrim($realRoot, DIRECTORY_SEPARATOR);
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('Invalid upload storage target segment');
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new \RuntimeException('Upload storage target symlink is not allowed');
            }
            if ($index === $last) {
                if (file_exists($current) && !is_file($current)) {
                    throw new \RuntimeException('Upload storage target is not a regular file');
                }
                continue;
            }
            if (!file_exists($current) && $createParents && !@mkdir($current, 0755) && !is_dir($current)) {
                throw new \RuntimeException('Cannot create upload storage target directory');
            }
            if (file_exists($current) && (!is_dir($current) || realpath($current) !== $current)) {
                throw new \RuntimeException('Invalid upload storage target directory');
            }
        }
        return $current;
    }

    /**
     * canonical 路径边界：拒绝不存在的文件、符号链接及配置根之外的目标。
     */
    private function resolveRegularFileWithinRoot(string $path, string $root, string $label): string
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new \RuntimeException('Invalid ' . $label . ' path');
        }

        $normalizedPath = $this->normalizePath($path);
        $normalizedRoot = $this->normalizePath($root);
        $realPath = realpath($normalizedPath);
        $realRoot = realpath($normalizedRoot);

        if ($realPath === false || $realRoot === false || !is_file($realPath)) {
            throw new \RuntimeException(ucfirst($label) . ' file does not exist');
        }
        if (is_link($normalizedPath)) {
            throw new \RuntimeException(ucfirst($label) . ' symlink is not allowed');
        }

        $realPath = rtrim($realPath, DIRECTORY_SEPARATOR);
        $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realPath . DIRECTORY_SEPARATOR, $rootPrefix) !== 0) {
            throw new \RuntimeException(ucfirst($label) . ' path is outside configured root');
        }

        return $realPath;
    }




    public function chunkDir(string $uploadId)
    {
        // 路径遍历防护 - 确保uploadId不包含路径分隔符
        if (strpos($uploadId, '/') !== false || strpos($uploadId, '\\') !== false || strpos($uploadId, '..') !== false) {
            throw new \InvalidArgumentException('上传ID包含非法路径字符');
        }

        $sanitized = (string)$this->sanitize($uploadId);
        // 双重验证
        if (strlen($sanitized) !== strlen($uploadId)) {
            throw new \InvalidArgumentException('上传ID包含非法字符');
        }

        $dir = rtrim($this->normalizePath($this->cfg['upload_temp']), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ul_' . $sanitized . DIRECTORY_SEPARATOR;

        $this->ensureDir($dir);

        // 确保目录在tempDir范围内
        $realDir = realpath($dir);
        $realTemp = realpath($this->normalizePath($this->cfg['upload_temp']));

        if ($realDir !== false && $realTemp !== false) {
            if (strpos($realDir, $realTemp) !== 0) {
                throw new \RuntimeException('上传目录超出临时目录范围');
            }
        }

        return $dir;
    }

    /**
     * @param string $dir
     */
    public function ensureDir($dir): void{
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create directory:' . $dir);
            }
        }
    }

    public function dailyFinalDir()
    {
        $d = $this->normalizePath($this->cfg['disks']['local']['root']) . date($this->cfg['attach_dir_save_rule'] ?? 'Ym') . DIRECTORY_SEPARATOR;
        $this->ensureDir($d);
        return $d;
    }

    public function dailyFinalTmpDir(?string $namespace = null)
    {
        $tempRoot = rtrim(
            $this->normalizePath($this->cfg['upload_temp']),
            DIRECTORY_SEPARATOR
        );

        if ($namespace !== null && $namespace !== '') {
            $segments = explode('/', $namespace);
            foreach ($segments as $segment) {
                if (
                    $segment === ''
                    || $segment === '.'
                    || $segment === '..'
                    || strpos($segment, '\\') !== false
                    || strpos($segment, "\0") !== false
                ) {
                    throw new \InvalidArgumentException('上传 namespace 包含非法路径段');
                }
            }

            $d = $tempRoot . DIRECTORY_SEPARATOR
                . implode(DIRECTORY_SEPARATOR, $segments) . DIRECTORY_SEPARATOR
                . date($this->cfg['attach_dir_save_rule'] ?? 'Ym')
                . DIRECTORY_SEPARATOR;
        } else {
            $d = $tempRoot . DIRECTORY_SEPARATOR
                . date($this->cfg['attach_dir_save_rule'] ?? 'Ym')
                . DIRECTORY_SEPARATOR;
        }

        $this->ensureDir($d);

        // 创建后按 canonical 路径复核，阻止 namespace 中的符号链接逃逸临时根。
        $realRoot = realpath($tempRoot);
        $realDir = realpath($d);
        if ($realRoot !== false && $realDir !== false) {
            $rootPrefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strpos(rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $rootPrefix) !== 0) {
                throw new \RuntimeException('上传 namespace 目录超出临时目录范围');
            }
        }

        return $d;
    }


    /**
     * 根据 uploadDir 根生成可访问 URL。
     * 1) 若配置了 baseUrl，则把 $path 相对化到 uploadDir 根再拼接到 baseUrl
     * 2) 若未配置 baseUrl，则返回文件系统路径（避免拼接出错误的绝对 URL）
     * @param string $path
     */
    public function fileUrl($path): array
    {
        // 使用 uploadDir 的绝对路径作为根，避免将绝对路径 ltrim 成假相对路径导致 URL 错误泄露
        // /www/wwwroot/wellcms/storage/upload
        $absUploadRoot = realpath($this->normalizePath($this->cfg['disks']['local']['root']));

        // /www/wwwroot/wellcms/storage/upload/tmp/READ.md
        $path = $this->normalizePath($path);
        $absPath = realpath($path);

        $basePath = $this->cfg['disks']['local']['root']; // /storage/upload/
        $baseUrl = $this->cfg['disks']['local']['url']; // /upload/

        if ($baseUrl && $absUploadRoot && $absPath) {
            $root = rtrim(str_replace('\\', '/', $absUploadRoot), '/');
            $abs  = str_replace('\\', '/', $absPath);

            if (strpos($abs, $root) === 0) {
                $rel = ltrim(substr($abs, strlen($root)), '/');
                $urlPath = str_replace('%2F', '/', rawurlencode($rel));
                // /storage/upload/tmp/READ.md and /upload/tmp/READ.md
                return [rtrim($basePath, '/') . '/' . $urlPath, rtrim($baseUrl, '/') . '/' . $urlPath];
            }

            // 不在上传根目录下，仅返回文件名，避免泄露绝对路径 /upload/tmp/READ.md
            return [rtrim($basePath, '/') . '/' . rawurlencode(basename($path)), rtrim($baseUrl, '/') . '/' . rawurlencode(basename($path))];
        }

        // 未配置 baseUrl：返回原始路径（由上层决定如何对外暴露）
        return [str_replace('\\', '/', (string)$path), str_replace('\\', '/', (string)$path)];
    }

    /**
     * @param string $path
     */
    public function detectMime($path)
    {
        $path = $this->normalizePath((string)$path);
        if (!file_exists($path)) return false;
        if (!function_exists('finfo_open')) return mime_content_type($path) ?: false;

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);
        return $mimeType ?: false;
    }

    /**
     * 分片/粗查阶段的危险 MIME 拦截（仅可执行代码与 Windows 可执行）。
     *
     * 注意：这是分片阶段的粗略检查（.part 无扩展名，finfo 按内容识别）。
     * html/js 有正当附件业务且可安全分发，交由完整文件阶段的 needsReview() 待审分流；
     * svg（image/svg+xml）已从 allowed_mimes 白名单移除，由 isDangerousFile() 的
     * 内容-白名单一致性校验拦截，此处不再需要 'xml' 子串误伤。
     *
     * @param string $mime
     * @return bool
     */
    public function blockDangerous(string $mime): bool{
        $m = mb_strtolower($mime);
        $dangerous = ['php', 'x-msdownload', 'x-dosexec'];
        foreach ($dangerous as $d) {
            if (strpos($m, $d) !== false) return true;
        }
        return false;
    }

    /**
     * @param string $path
     * @param null $expectedMime
     */
    public function validateMimeFromContent(string $path, ?string $expectedMime = null)
    {
        $realMime = $this->detectMime($path);
        if ($realMime === false) {
            throw new \RuntimeException('无法检测文件MIME类型');
        }

        // 阻止危险的MIME类型
        if ($this->blockDangerous($realMime)) {
            throw new \RuntimeException('不允许的文件类型');
        }

        // 如果提供了期望的MIME类型，验证是否匹配
        if ($expectedMime !== null && $realMime !== $expectedMime) {
            throw new \RuntimeException("MIME类型不匹配: 期望 $expectedMime, 实际 $realMime");
        }

        return $realMime;
    }

    /**
     * @param array $s
     */
    public function sanitize($s)
    {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $s);
    }

    /**
     * @param string $dir
     */
    public function cleanupDir($dir): void{
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir . $f);
        }
        @rmdir($dir);
    }

    /**
     * @param string $filename
     */
    public function safeFilename($filename)
    {
        // 路径遍历防护
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            throw new \InvalidArgumentException('文件名包含非法路径字符');
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        /* $name = pathinfo($filename, PATHINFO_FILENAME);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$name);
        $safe = substr($safe, 0, 100);
        $timestamp = time();
        $extPart = $ext !== '' ? '.' . strtolower($ext) : '';
        return $safe . '_' . $timestamp . $extPart; */
        $extPart = $ext !== '' ? '.' . strtolower($ext) : '_';
        return uniqid() . $extPart;
    }

    /**
     * @param string $filename
     */
    public function allowedExt($filename, array $specificList = [])
    {
        $allowed = !empty($specificList) ? $specificList : (!empty($this->allowedExt) ? $this->allowedExt : (isset($this->cfg['allowed_ext']) ? $this->cfg['allowed_ext'] : ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z', 'json', 'html', 'css', 'js']));
        $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    }

    public function isImage(string $path, array $allowedTypes = []): bool
    {
        $path = $this->normalizePath($path);
        // 1. 基础验证
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        // 2. 验证文件大小（防止超大文件攻击）
        $maxSize = 10 * 1024 * 1024; // 10MB
        if (filesize($path) > $maxSize) {
            return false;
        }

        // 3. 使用 finfo 检测真实的 MIME 类型
        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($path);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($path);
        } else {
            return false; // 无法检测
        }

        // 4. 验证是否为图片类型
        if (strpos($mimeType, 'image/') !== 0) {
            return false;
        }

        // 5. 验证是否在允许的类型列表中（可选）
        if (!empty($allowedTypes)) {
            $mimeLower = strtolower($mimeType);
            $imageExt = str_replace('image/', '', $mimeLower);

            foreach ($allowedTypes as $key => $val) {
                // 如果是映射格式: 'image/jpeg' => ['jpg', 'jpeg'] 或 'image/gif' => ['gif']
                if (is_string($key) && strtolower($key) === $mimeLower) {
                    return true;
                }

                if (is_array($val)) {
                    // 检查扩展名是否在数组中
                    foreach ($val as $ext) {
                        if (is_string($ext) && strtolower($ext) === $imageExt) {
                            return true;
                        }
                    }
                } elseif (is_string($val)) {
                    // 平铺格式: ['jpeg', 'png'] 或单个字符串
                    $valLower = strtolower($val);
                    if ($valLower === $imageExt || $valLower === $mimeLower) {
                        return true;
                    }
                }
            }
            return false;
        }

        return true;
    }

    /**
     * 将文件从临时目录“转正”到正式存储目录
     *
     * @param string $tempPath 临时文件路径
     * @return array [newPath, newUrl]
     */
    public function promoteFile(string $tempPath, bool $preserveSource = false): array
    {
        // 必须使用 canonical 临时根内的普通文件，禁止路径遍历和外指符号链接。
        $tempPath = $this->resolveManagedTempFile($tempPath);

        // 1. 生成正式目录 Ym 格式
        $finalDir = $this->dailyFinalDir();
        $filename = basename($tempPath);
        $newPath = $finalDir . $filename;
        if (file_exists($newPath)) {
            throw new \RuntimeException('Formal storage target already exists: ' . $newPath);
        }

        // 事务绑定保留临时源至提交确认；既有其他调用方仍保持 move 语义。
        if ($preserveSource) {
            $source = fopen($tempPath, 'rb');
            $target = fopen($newPath, 'x+b');
            if (!is_resource($source) || !is_resource($target)) {
                if (is_resource($source)) fclose($source);
                if (is_resource($target)) { fclose($target); unlink($newPath); }
                throw new \RuntimeException('Cannot prepare promoted file copy');
            }
            try {
                $copied = stream_copy_to_stream($source, $target);
                if ($copied === false || $copied !== filesize($tempPath) || !fflush($target)) {
                    throw new \RuntimeException('Incomplete promoted file copy');
                }
            } catch (\Throwable $error) {
                fclose($source);
                fclose($target);
                unlink($newPath);
                throw $error;
            }
            fclose($source);
            fclose($target);
        } elseif (!rename($tempPath, $newPath)) {
            throw new \RuntimeException('Failed to move file from temp to formal storage');
        }

        // 3. 生成正式 URL
        return $this->fileUrl($newPath);
    }
}
