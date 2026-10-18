<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * Deployer
 * 
 * 处理升级包的解压缩与文件覆盖
 */
class Deployer
{
    private const TRANSACTION_DIRECTORY = 'storage/.wellcms/core-upgrade-transactions/';

    /** @var array<string,string> 当前实例实际签发且尚未结束的事务 */
    private $activeTransactions = [];

    public function __construct(array $appConfig)
    {
        // 保留既有依赖注入签名；每次发布状态通过显式事务句柄传递。
    }

    /**
     * 解压并覆盖文件
     * 
     * @param string $zipPath 压缩包绝对路径
     * @param string $destPath 覆盖目标根目录（通常是 APP_PATH）
     * @throws \RuntimeException
     */
    public function extractAndOverwrite(string $zipPath, string $destPath): void
    {
        $transaction = $this->begin($zipPath, $destPath);
        try {
            $this->commit($transaction);
        } catch (\Throwable $e) {
            if (is_dir($transaction['root'] ?? '')) {
                $this->rollback($transaction);
            }
            throw $e;
        }
    }

    /**
     * 解压、预检并应用一个可回滚的文件事务。
     *
     * @return array 显式事务句柄，必须交给 commit() 或 rollback()
     */
    public function begin(string $zipPath, string $destPath): array
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new \RuntimeException('Migration zip file not found or unreadable: ' . $zipPath);
        }

        $destination = $this->normalizeDestination($destPath);
        $transactionBase = $destination . self::TRANSACTION_DIRECTORY;
        $this->ensureDirectory($transactionBase);

        $transactionId = bin2hex(random_bytes(16));
        $transactionRoot = $transactionBase . $transactionId . '/';
        $stagePath = $transactionRoot . 'stage/';
        $backupPath = $transactionRoot . 'backup/';
        $transaction = [
            'id' => $transactionId,
            'destination' => $destination,
            'root' => $transactionRoot,
            'stage' => $stagePath,
            'backup' => $backupPath,
            'files' => [],
            'created_dirs' => [],
            'status' => 'preparing',
        ];
        $this->activeTransactions[$transactionId] = $transactionRoot;

        try {
            $this->ensureDirectory($stagePath);
            $this->ensureDirectory($backupPath);
            $zip = new \Framework\Utils\ZipUtility();
            $zip->unzip($zipPath, $stagePath);
            $sourcePath = $this->resolveSourceRoot($stagePath);
            $files = $this->collectDeployableFiles($sourcePath);
            if (empty($files)) {
                throw new \RuntimeException('Upgrade package contains no deployable core files');
            }

            $this->preflight($sourcePath, $destination, $files);
            $transaction['status'] = 'applying';
            foreach ($files as $relative) {
                $sourceFile = $sourcePath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $targetFile = $destination . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $backupFile = $backupPath . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                $this->ensureTargetDirectory(dirname($targetFile), $destination, $transaction['created_dirs']);
                $this->ensureDirectory(dirname($backupFile));

                $hadPrevious = is_file($targetFile);
                $previousPermissions = $hadPrevious ? @fileperms($targetFile) : false;
                if ($hadPrevious) {
                    $this->moveFile($targetFile, $backupFile);
                }

                // 在移动新文件前先登记，使“旧文件已备份、新文件未落位”的中断也可恢复。
                $transaction['files'][] = [
                    'relative' => $relative,
                    'target' => $targetFile,
                    'backup' => $backupFile,
                    'had_previous' => $hadPrevious,
                ];
                $this->moveFile($sourceFile, $targetFile);
                if (is_int($previousPermissions)
                    && !@chmod($targetFile, $previousPermissions & 0777)) {
                    throw new \RuntimeException('Unable to preserve core file permissions: ' . $relative);
                }
            }

            $transaction['status'] = 'applied';
            return $transaction;
        } catch (\Throwable $e) {
            try {
                $this->rollback($transaction);
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException(
                    'Core deployment failed and rollback failed: ' . $rollbackError->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /** 提交文件事务，只有此时才删除升级前备份。 */
    public function commit(array $transaction): void
    {
        $this->assertTransaction($transaction);
        if (($transaction['status'] ?? '') !== 'applied') {
            throw new \RuntimeException('Core deployment transaction is not ready to commit');
        }
        $this->removeTransactionRoot($transaction['root']);
        unset($this->activeTransactions[$transaction['id']]);
    }

    /** 逆序恢复升级前文件，并清理本次 stage/backup。 */
    public function rollback(array $transaction): void
    {
        $this->assertTransaction($transaction);
        $failures = [];
        $files = is_array($transaction['files'] ?? null) ? $transaction['files'] : [];
        foreach (array_reverse($files) as $entry) {
            $target = (string)($entry['target'] ?? '');
            $backup = (string)($entry['backup'] ?? '');
            $hadPrevious = !empty($entry['had_previous']);
            try {
                if (is_file($target) || is_link($target)) {
                    if (!@unlink($target)) {
                        throw new \RuntimeException('Unable to remove applied file: ' . $target);
                    }
                } elseif (file_exists($target)) {
                    throw new \RuntimeException('Applied target changed type during rollback: ' . $target);
                }

                if ($hadPrevious) {
                    if (!is_file($backup)) {
                        throw new \RuntimeException('Core deployment backup is missing: ' . $backup);
                    }
                    $this->ensureDirectory(dirname($target));
                    $this->moveFile($backup, $target);
                }
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        $createdDirs = is_array($transaction['created_dirs'] ?? null)
            ? array_reverse($transaction['created_dirs']) : [];
        foreach ($createdDirs as $directory) {
            if (is_dir($directory) && !@rmdir($directory)) {
                $entries = @scandir($directory);
                if (is_array($entries) && count($entries) <= 2) {
                    $failures[] = 'Unable to remove created directory: ' . $directory;
                }
            }
        }

        if ($failures) {
            throw new \RuntimeException(implode('; ', $failures));
        }
        $this->removeTransactionRoot($transaction['root']);
        unset($this->activeTransactions[$transaction['id']]);
    }

    private function normalizeDestination(string $destPath): string
    {
        $real = realpath($destPath);
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            throw new \RuntimeException('Upgrade destination is missing or not writable');
        }
        return rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    }

    private function resolveSourceRoot(string $stagePath): string
    {
        $sourcePath = rtrim($stagePath, '/\\') . DIRECTORY_SEPARATOR;
        while (true) {
            $entries = @scandir($sourcePath);
            if (!is_array($entries)) {
                throw new \RuntimeException('Unable to inspect extracted upgrade package');
            }
            $dirs = [];
            $hasFile = false;
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $fullPath = $sourcePath . $entry;
                if (is_dir($fullPath) && !is_link($fullPath)) {
                    if ($entry !== '__MACOSX' && $entry[0] !== '.') {
                        $dirs[] = $fullPath;
                    }
                } else {
                    $hasFile = true;
                }
            }
            if ($hasFile || count($dirs) !== 1) {
                return $sourcePath;
            }
            $sourcePath = rtrim($dirs[0], '/\\') . DIRECTORY_SEPARATOR;
        }
    }

    /** @return string[] */
    private function collectDeployableFiles(string $sourcePath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Symbolic links are not allowed in core packages');
            }
            if (!$item->isFile()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourcePath)));
            if ($this->isProtectedPath($relative)) continue;
            $files[] = $relative;
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function isProtectedPath(string $relative): bool
    {
        return strpos($relative, 'config/') === 0 || strpos($relative, 'storage/') === 0;
    }

    /** @param string[] $files */
    private function preflight(string $sourcePath, string $destination, array $files): void
    {
        foreach ($files as $relative) {
            $source = $sourcePath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $target = $destination . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($source) || !is_readable($source) || is_link($source)) {
                throw new \RuntimeException('Upgrade stage file is unreadable: ' . $relative);
            }
            $this->assertSafeTargetPath($destination, $target);
            if (is_dir($target)) {
                throw new \RuntimeException('Upgrade target file conflicts with a directory: ' . $relative);
            }
            if (is_file($target) && !is_writable($target)) {
                throw new \RuntimeException('Upgrade target file is not writable: ' . $relative);
            }

            $ancestor = dirname($target);
            while (!file_exists($ancestor) && $ancestor !== $destination) {
                $ancestor = dirname($ancestor);
            }
            if (!is_dir($ancestor) || !is_writable($ancestor)) {
                throw new \RuntimeException('Upgrade target directory is not writable: ' . $relative);
            }
        }
    }

    private function assertSafeTargetPath(string $destination, string $target): void
    {
        $relative = substr($target, strlen($destination));
        if ($relative === false || $relative === '' || strpos(str_replace('\\', '/', $relative), '../') !== false) {
            throw new \RuntimeException('Upgrade target escapes destination');
        }
        $current = rtrim($destination, '/\\');
        $segments = explode(DIRECTORY_SEPARATOR, dirname($relative));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') continue;
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \RuntimeException('Upgrade target ancestor cannot be a symbolic link');
            }
            if (file_exists($current) && !is_dir($current)) {
                throw new \RuntimeException('Upgrade target ancestor conflicts with a file');
            }
        }
        if (is_link($target)) {
            throw new \RuntimeException('Upgrade target cannot be a symbolic link');
        }
    }

    /** @param string[] $createdDirs */
    private function ensureTargetDirectory(string $directory, string $destination, array &$createdDirs): void
    {
        $relative = substr($directory, strlen($destination));
        $current = rtrim($destination, '/\\');
        foreach (explode(DIRECTORY_SEPARATOR, (string)$relative) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current) || (file_exists($current) && !is_dir($current))) {
                throw new \RuntimeException('Unable to create safe upgrade target directory');
            }
            if (!is_dir($current)) {
                if (!\Framework\Utils\DirectoryHelper::mkdir($current)) {
                    throw new \RuntimeException('Unable to create upgrade target directory: ' . $current);
                }
                $createdDirs[] = $current;
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)
            || (!is_dir($directory) && !\Framework\Utils\DirectoryHelper::mkdir($directory))) {
            throw new \RuntimeException('Unable to create core deployment directory: ' . $directory);
        }
    }

    /** 原子同盘移动；protected 便于隔离测试注入确定性中断。 */
    protected function moveFile(string $source, string $destination): void
    {
        if (!@rename($source, $destination)) {
            throw new \RuntimeException('Unable to atomically move core deployment file');
        }
    }

    private function assertTransaction(array $transaction): void
    {
        foreach (['id', 'destination', 'root', 'stage', 'backup'] as $field) {
            if (!isset($transaction[$field]) || !is_string($transaction[$field])) {
                throw new \RuntimeException('Invalid core deployment transaction');
            }
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $transaction['id'])) {
            throw new \RuntimeException('Invalid core deployment transaction identity');
        }

        $realDestination = realpath(rtrim($transaction['destination'], '/\\'));
        $destination = $realDestination === false
            ? '' : rtrim($realDestination, '/\\') . DIRECTORY_SEPARATOR;
        if ($destination === '' || $transaction['destination'] !== $destination) {
            throw new \RuntimeException('Core deployment destination is invalid');
        }

        $prefix = $destination . self::TRANSACTION_DIRECTORY;
        $root = $prefix . $transaction['id'] . DIRECTORY_SEPARATOR;
        if ($transaction['root'] !== $root
            || $transaction['stage'] !== $root . 'stage' . DIRECTORY_SEPARATOR
            || $transaction['backup'] !== $root . 'backup' . DIRECTORY_SEPARATOR) {
            throw new \RuntimeException('Core deployment transaction path is invalid');
        }
        if (($this->activeTransactions[$transaction['id']] ?? null) !== $root) {
            throw new \RuntimeException('Unknown or completed core deployment transaction');
        }
        if (!is_array($transaction['files'] ?? null)
            || !is_array($transaction['created_dirs'] ?? null)) {
            throw new \RuntimeException('Invalid core deployment transaction entries');
        }

        foreach ($transaction['files'] as $entry) {
            if (!is_array($entry)
                || !is_string($entry['relative'] ?? null)
                || !is_string($entry['target'] ?? null)
                || !is_string($entry['backup'] ?? null)
                || !is_bool($entry['had_previous'] ?? null)) {
                throw new \RuntimeException('Invalid core deployment file transaction entry');
            }
            $relative = $entry['relative'];
            $this->assertSafeRelative($relative);
            $nativeRelative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($entry['target'] !== $destination . $nativeRelative
                || $entry['backup'] !== $transaction['backup'] . $nativeRelative) {
                throw new \RuntimeException('Core deployment file transaction path is invalid');
            }
        }

        foreach ($transaction['created_dirs'] as $directory) {
            if (!is_string($directory) || strpos($directory, $destination) !== 0) {
                throw new \RuntimeException('Invalid core deployment created directory');
            }
            $relative = substr($directory, strlen($destination));
            $this->assertSafeRelative((string)$relative);
            if ($directory !== $destination
                    . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative)) {
                throw new \RuntimeException('Core deployment created directory path is invalid');
            }
        }
    }

    private function assertSafeRelative(string $relative): void
    {
        if ($relative === '' || $relative[0] === '/' || $relative[0] === '\\'
            || strpos($relative, "\0") !== false || strpos($relative, '\\') !== false) {
            throw new \RuntimeException('Core deployment relative path is invalid');
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Core deployment relative path is invalid');
            }
        }
    }

    private function removeTransactionRoot(string $root): void
    {
        if (is_dir($root)
            && !\Framework\Utils\DirectoryHelper::rmdirRecursive($root, true)) {
            throw new \RuntimeException('Unable to clean core deployment transaction directory');
        }
    }
}
