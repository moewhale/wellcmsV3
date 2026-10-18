<?php

declare(strict_types=1);

namespace Framework\Scheduler\Jobs;

/**
 * 自动生成类映射表任务 (Generate Classmap Job)
 * 
 * 职责：
 * 扫描 app/ src/ plugins/ 目录，生成 storage/tmp/classmap.php
 * 目的是加速 app/Core/Autoload.php 的加载性能，将动态磁盘扫描转为内存数组查找。
 */
class GenerateClassmapJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    private const CLASSMAP_SCHEMA = 'wellcms-classmap-v1';

    /** @var \Framework\Scheduler\TaskManage */
    private $taskManage;

    public function __construct(\Framework\Scheduler\TaskManage $taskManage)
    {
        $this->taskManage = $taskManage;
    }

    /**
     * 执行生成逻辑 (自愈监控模式)
     * 
     * @param string|null $_task_id 系统自动注入的任务ID
     * @return array
     */
    public function handle(?string $_task_id = null): array
    {
        $basePath = \defined('APP_PATH') ? \APP_PATH : \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        $targetFile = $basePath . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'classmap.php';

        // DEBUG0 只校验固定文件、release root 与小型 epoch，不扫描源码或读取源码 mtime。
        $classmap = null;
        if (is_file($targetFile)) {
            try {
                $candidate = require $targetFile;
                $classmap = is_array($candidate) ? $candidate : null;
            } catch (\Throwable $e) {
                $classmap = null;
            }
        }
        $root = $this->applicationRootIdentity($basePath);
        $epoch = $this->artifactEpoch($basePath);
        $declaredRoot = isset($classmap['app_root']) && is_string($classmap['app_root'])
            ? realpath($classmap['app_root']) : false;
        $currentRoot = realpath($root);
        $identityMatches = isset($classmap['classes'], $classmap['artifact_epoch'])
            && ($classmap['schema'] ?? '') === self::CLASSMAP_SCHEMA
            && is_array($classmap['classes'])
            && is_string($classmap['artifact_epoch'])
            && $declaredRoot !== false
            && $currentRoot !== false
            && $declaredRoot === $currentRoot
            && hash_equals($classmap['artifact_epoch'], $epoch);
        $debugExpired = defined('DEBUG') && DEBUG > 0 && is_file($targetFile)
            && (time() - (int)filemtime($targetFile) > 86400);
        $needsScan = !$identityMatches || $debugExpired;

        $result = [
            'status' => 'skipped',
            'msg' => 'Classmap is up to date',
            'path' => $targetFile
        ];

        if ($needsScan) {
            $result = $this->generate($basePath, $targetFile);
        }

        // 2. [自循环] 1 小时后再次检查 (保证目录被清空后能快速发现)
        // 使用 dedupeKey 确保队列中永远只有一个监控任务
        $dispatch = $this->taskManage->createTask([
            'className'  => self::class,
            'methodName' => 'handle',
            'args'       => [],
            'priority'   => 9,
            'scheduledAt' => time() + 3600, // 1 小时后
            'dedupeKey'  => 'system:classmap_monitor'
        ]);

        if (!\Framework\Scheduler\TaskDispatchResult::isAccepted($dispatch)) {
            return [
                'status' => 'error',
                'outcome' => \Framework\Scheduler\TaskDispatchResult::outcome($dispatch),
                'code' => \Framework\Scheduler\TaskDispatchResult::code($dispatch)
                    ?? 'TASK_DISPATCH_UNKNOWN',
                'taskId' => \Framework\Scheduler\TaskDispatchResult::taskId($dispatch),
                'msg' => 'classmap_monitor_dispatch_failed',
                'classmap' => $result,
            ];
        }

        return $result;
    }

    /**
     * 实际的扫描与生成逻辑
     */
    private function generate(string $basePath, string $targetFile): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $rootBefore = $this->applicationRootIdentity($basePath);
            $epochBefore = $this->artifactEpoch($basePath);
            $classmap = $this->scanClasses($basePath);
            $rootAfter = $this->applicationRootIdentity($basePath);
            $epochAfter = $this->artifactEpoch($basePath);
            if ($rootBefore !== $rootAfter || !hash_equals($epochBefore, $epochAfter)) {
                continue;
            }

            $payload = [
                'schema' => self::CLASSMAP_SCHEMA,
                'app_root' => $rootAfter,
                'artifact_epoch' => $epochAfter,
                'classes' => $classmap,
            ];
            $content = "<?php\n/**\n * WellCMS Auto-generated Classmap\n"
                . " * Generated at: " . date('Y-m-d H:i:s') . "\n */\nreturn "
                . var_export($payload, true) . ";\n";
            $this->atomicWrite($targetFile, $content);

            $publishedRoot = $this->applicationRootIdentity($basePath);
            $publishedEpoch = $this->artifactEpoch($basePath);
            if ($rootAfter === $publishedRoot && hash_equals($epochAfter, $publishedEpoch)) {
                return [
                    'status' => 'success',
                    'count' => count($classmap),
                    'path' => $targetFile
                ];
            }
        }

        throw new \RuntimeException('Classmap inputs changed repeatedly during publication');
    }

    /**
     * 扫描所有 PSR 目录；测试、私有 vendor 与运行夹具不进入生产 classmap。
     */
    private function scanClasses(string $basePath): array
    {
        $files = [];
        $scanConfig = [
            $basePath . 'app',
            $basePath . 'src',
            $basePath . 'plugins'
        ];
        $excludedDirectories = ['Tests', 'tests', 'vendor', '.runtime-test'];

        foreach ($scanConfig as $directoryPath) {
            $scanRoot = realpath($directoryPath);
            if ($scanRoot === false || !is_dir($scanRoot)) continue;
            $normalizedRoot = rtrim(str_replace('\\', '/', $scanRoot), '/') . '/';

            $directory = new \RecursiveDirectoryIterator(
                $scanRoot,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
            );
            $filter = new \RecursiveCallbackFilterIterator(
                $directory,
                function (\SplFileInfo $entry) use ($excludedDirectories): bool {
                    return !$entry->isDir()
                        || !in_array($entry->getFilename(), $excludedDirectories, true);
                }
            );
            $iterator = new \RecursiveIteratorIterator(
                $filter,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir() || strtolower($file->getExtension()) !== 'php') continue;
                $fullPath = $file->getRealPath();
                if (!is_string($fullPath)) continue;
                $normalizedPath = str_replace('\\', '/', $fullPath);
                if (strpos($normalizedPath, $normalizedRoot) !== 0) continue;
                $files[$normalizedPath] = $fullPath;
            }
        }

        ksort($files, SORT_STRING);
        $classmap = [];
        foreach ($files as $fullPath) {
            foreach ($this->declaredClasses($fullPath) as $className) {
                if (isset($classmap[$className]) && $classmap[$className] !== $fullPath) {
                    throw new \RuntimeException("Duplicate classmap declaration: {$className}");
                }
                $classmap[$className] = $fullPath;
            }
        }
        ksort($classmap, SORT_STRING);
        return $classmap;
    }

    /**
     * 以 PHP 7.2 token 流提取真实命名类型，跳过匿名类和 Foo::class 常量。
     */
    private function declaredClasses(string $file): array
    {
        $content = file_get_contents($file);
        if (!is_string($content)) {
            throw new \RuntimeException("Unable to read classmap source: {$file}");
        }
        $tokens = token_get_all($content);
        $namespace = '';
        $classes = [];
        $previous = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if (trim($token) !== '') {
                    $previous = $token;
                }
                continue;
            }

            $id = $token[0];
            if ($id === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $part = $tokens[$j];
                    if (!is_array($part)) {
                        if ($part === ';' || $part === '{') {
                            $i = $j;
                            break;
                        }
                        continue;
                    }
                    $qualifiedName = defined('T_NAME_QUALIFIED')
                        && $part[0] === constant('T_NAME_QUALIFIED');
                    $fullyQualifiedName = defined('T_NAME_FULLY_QUALIFIED')
                        && $part[0] === constant('T_NAME_FULLY_QUALIFIED');
                    if ($part[0] === T_STRING || $part[0] === T_NS_SEPARATOR
                        || $qualifiedName || $fullyQualifiedName) {
                        $namespace .= $part[1];
                    }
                }
                $namespace = trim($namespace, '\\');
                $previous = null;
                continue;
            }

            $typeDeclaration = $id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT;
            $classConstant = $id === T_CLASS && $previous === T_DOUBLE_COLON;
            $anonymousClass = $id === T_CLASS && $previous === T_NEW;
            if ($typeDeclaration && !$classConstant && !$anonymousClass) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $name = $tokens[$j];
                    if (is_array($name) && $name[0] === T_STRING) {
                        $classes[] = $namespace === '' ? $name[1] : $namespace . '\\' . $name[1];
                        $i = $j;
                        break;
                    }
                    if (!is_array($name) && ($name === '{' || $name === '(')) {
                        break;
                    }
                }
            }

            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $previous = $id;
            }
        }

        return $classes;
    }

    /**
     * 归一化并绑定当前 release 的真实根。
     */
    private function applicationRootIdentity(string $basePath): string
    {
        $realPath = realpath($basePath);
        if ($realPath === false || !is_dir($realPath)) {
            throw new \RuntimeException("Invalid classmap application root: {$basePath}");
        }
        return rtrim(str_replace('\\', '/', $realPath), '/') . '/';
    }

    /**
     * Compile::flushCache() 发布的 256-bit epoch；旧安装无文件时保持空身份。
     */
    private function artifactEpoch(string $basePath): string
    {
        $path = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR
            . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'artifact_epoch';
        if (!is_file($path)) {
            return '';
        }
        $epoch = file_get_contents($path);
        if (!is_string($epoch) || preg_match('/^[a-f0-9]{64}$/D', $epoch) !== 1) {
            throw new \RuntimeException("Invalid classmap artifact epoch: {$path}");
        }
        return $epoch;
    }

    /**
     * 同目录完整写入后以 rename 单点发布，固定目标永不暴露截断内容。
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create classmap directory: {$dir}");
        }

        $temporary = tempnam($dir, '.wellcms-classmap-');
        if ($temporary === false || realpath(dirname($temporary)) !== realpath($dir)) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException("Unable to create classmap temporary file: {$path}");
        }

        $fp = null;
        try {
            $fp = fopen($temporary, 'wb');
            if ($fp === false) {
                throw new \RuntimeException("Unable to open classmap temporary file: {$temporary}");
            }
            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($fp, substr($content, $offset, 1048576));
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException("Unable to write complete classmap: {$path}");
                }
                $offset += $written;
            }
            if (!fflush($fp)) {
                throw new \RuntimeException("Unable to flush classmap temporary file: {$path}");
            }
            if (function_exists('fsync') && !fsync($fp)) {
                throw new \RuntimeException("Unable to synchronize classmap temporary file: {$path}");
            }
            $permissions = is_file($path) ? fileperms($path) : false;
            $mode = is_int($permissions) ? ($permissions & 0777) : (0666 & ~umask());
            if (!chmod($temporary, $mode)) {
                throw new \RuntimeException("Unable to set classmap permissions: {$path}");
            }
            fclose($fp);
            $fp = null;
            if (!rename($temporary, $path)) {
                throw new \RuntimeException("Unable to atomically publish classmap: {$path}");
            }
            $temporary = '';
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
            throw $e;
        }
    }
}
