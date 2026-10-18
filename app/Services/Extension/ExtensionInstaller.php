<?php

declare(strict_types=1);

namespace App\Services\Extension;

use App\Services\Market\SignatureHelper;
use Framework\Utils\DirectoryHelper;
use Framework\Utils\SecurityHelper;

/**
 * 扩展物理部署器：签名权证、强哈希、同盘暂存与原子切换。
 */
class ExtensionInstaller
{
    /** @var \App\Services\Market\MarketClient */
    private $market;
    /** @var \App\Services\Upgrade\Downloader */
    private $downloader;
    /** @var \App\Interfaces\LanguageLoaderInterface|null */
    private $language;
    /** @var array */
    private $appConfig;
    /** @var string */
    private $pluginPath;
    /** @var string */
    private $themePath;
    /** @var string */
    private $tmpPath;

    public function __construct(
        \App\Services\Market\MarketClient $market,
        \App\Services\Upgrade\Downloader $downloader,
        ?\App\Interfaces\LanguageLoaderInterface $language,
        array $appConfig,
        array $pluginConfig,
        array $viewConfig
    ) {
        $this->market = $market;
        $this->downloader = $downloader;
        $this->language = $language;
        $this->appConfig = $appConfig;
        $this->pluginPath = rtrim($pluginConfig['plugins_path'], '/\\') . DIRECTORY_SEPARATOR;
        $this->themePath = rtrim($viewConfig['themes_path'], '/\\') . DIRECTORY_SEPARATOR;
        $this->tmpPath = rtrim($appConfig['tmp_path'], '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 准备并原子切换扩展目录。返回的事务必须由调用方 commit 或 rollback。
     */
    public function execute(string $dir, string $type, int $storeId, string $expectedHash = ''): array
    {
        $this->assertIdentity($dir, $type, $storeId);
        $manifest = $this->getDownloadManifest($storeId, $dir, $type);
        if ($expectedHash !== ''
            && (!preg_match('/^[a-f0-9]{64}$/i', $expectedHash)
                || !hash_equals(strtolower($manifest['hash']), strtolower($expectedHash)))) {
            throw new \RuntimeException('Expected extension hash does not match signed manifest');
        }
        $basePath = $type === 'theme' ? $this->themePath : $this->pluginPath;
        $operationId = bin2hex(random_bytes(12));
        $stagePath = $basePath . '.wellcms-stage-' . $dir . '-' . $operationId;
        $backupPath = $basePath . '.wellcms-backup-' . $dir . '-' . $operationId;
        $targetPath = $basePath . $dir;
        $zipFile = $this->tmpPath . 'wellcms-extension-' . $operationId . '.zip';
        $hadPrevious = is_dir($targetPath);

        $this->ensureDirectory($this->tmpPath);
        $this->ensureDirectory($stagePath);

        try {
            $this->downloader->download($manifest['url'], $zipFile, $manifest['hash']);

            $zip = new \Framework\Utils\ZipUtility();
            $zip->unzip($zipFile, $stagePath, false);

            $extractedPath = $stagePath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($extractedPath)) {
                throw new \RuntimeException('Extension archive root directory does not match signed dir');
            }
            $this->validatePackage($extractedPath, $type, $manifest);
            if (!@unlink($zipFile)) {
                throw new \RuntimeException('Failed to remove verified extension archive');
            }

            if ($hadPrevious && !@rename($targetPath, $backupPath)) {
                throw new \RuntimeException('Failed to move current extension to transaction backup');
            }

            if (!@rename($extractedPath, $targetPath)) {
                throw new \RuntimeException('Atomic extension directory switch failed');
            }

            return [
                'status' => 'success',
                'dir' => $dir,
                'target_path' => $targetPath,
                'backup_path' => $backupPath,
                'stage_path' => $stagePath,
                'had_previous' => $hadPrevious,
                'version' => (string)$manifest['version'],
                'store_id' => $storeId,
                'type' => $type,
            ];
        } catch (\Throwable $e) {
            $cleanupFailures = [];
            if (!is_dir($targetPath) && $hadPrevious && is_dir($backupPath)) {
                if (!@rename($backupPath, $targetPath)) {
                    $cleanupFailures[] = 'original directory restore failed';
                }
            }
            try {
                $this->removeDirectoryIfExists($stagePath);
            } catch (\Throwable $cleanupError) {
                $cleanupFailures[] = $cleanupError->getMessage();
            }
            if (is_file($zipFile) && !@unlink($zipFile)) {
                $cleanupFailures[] = 'download archive cleanup failed';
            }
            if ($cleanupFailures) {
                throw new \RuntimeException(
                    'Extension preparation failed and cleanup failed: ' . implode('; ', $cleanupFailures),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /** 确认部署事务，删除旧版本备份。 */
    public function commit(array $transaction): void
    {
        $this->assertTransaction($transaction);
        // 先清理不参与回滚的暂存目录；旧版本备份必须最后删除。
        $this->removeDirectoryIfExists($transaction['stage_path']);
        $this->removeDirectoryIfExists($transaction['backup_path']);
    }

    /** 回滚部署事务到切换前的目录状态。 */
    public function rollback(array $transaction): void
    {
        $this->assertTransaction($transaction);
        $targetPath = $transaction['target_path'];
        $backupPath = $transaction['backup_path'];
        $hadPrevious = (bool)$transaction['had_previous'];

        $this->removeDirectoryIfExists($targetPath);
        if ($hadPrevious) {
            if (!is_dir($backupPath) || !@rename($backupPath, $targetPath)) {
                throw new \RuntimeException('Failed to restore original extension directory');
            }
        }
        $this->removeDirectoryIfExists($transaction['stage_path']);
    }

    private function getDownloadManifest(int $storeId, string $dir, string $type): array
    {
        $params = $this->market->getCommonParams();
        $params['storeid'] = $storeId;

        $response = $this->market->request('download.html', $params);
        $result = SecurityHelper::jsonDecode($response);
        if (($result['status'] ?? '') !== 'success' || !is_array($result['data'] ?? null)) {
            throw new \RuntimeException((string)($result['message'] ?? 'Store download authorization failed'));
        }

        $manifest = $result['data'];
        $required = ['url', 'hash', 'version', 'storeid', 'type', 'dir', 'expires_at', 'sign'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $manifest) || $manifest[$field] === '') {
                throw new \RuntimeException('Incomplete signed download manifest: ' . $field);
            }
        }

        $credentials = $this->market->getCredentials();
        $sessionSecret = is_array($credentials) ? (string)($credentials['session_secret'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{64}$/i', $sessionSecret)
            || !SignatureHelper::verifyRequest($manifest, $sessionSecret)) {
            throw new \RuntimeException('Invalid download manifest signature');
        }
        if ((int)$manifest['expires_at'] < time()) {
            throw new \RuntimeException('Download manifest expired');
        }
        if ((int)$manifest['storeid'] !== $storeId
            || !hash_equals((string)$manifest['dir'], $dir)
            || !hash_equals((string)$manifest['type'], $type)) {
            throw new \RuntimeException('Download manifest identity mismatch');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', (string)$manifest['hash'])) {
            throw new \RuntimeException('Download manifest hash is invalid');
        }
        if (parse_url((string)$manifest['url'], PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('Store download URL must use HTTPS');
        }

        $manifest['hash'] = strtolower((string)$manifest['hash']);
        return $manifest;
    }

    /**
     * 向商店请求下载地址的兼容入口。
     */
    /* private function getDownloadUrl(int $storeId): array
    {
        $params = $this->market->getCommonParams();
        $params['storeid'] = $storeId;
        $result = SecurityHelper::jsonDecode($this->market->request('download.html', $params));
        if (($result['status'] ?? '') === 'success' && !empty($result['data']['url'])) {
            return [
                'status' => 'success',
                'url' => $result['data']['url'],
                'hash' => $result['data']['hash'] ?? '',
            ];
        }
        $message = $result['message'] ?? '';
        if ($message === '' && $this->language) {
            $message = $this->language->get('plugin_download_failed');
        }
        return ['status' => 'error', 'message' => $message ?: 'Extension download authorization failed'];
    } */

    /* private function ensureTmp(): void
    {
        $this->ensureDirectory($this->tmpPath);
    }

    private function cleanTmp(string $dir): void
    {
        $shadow = $this->tmpPath . 'shadow_' . $dir;
        $this->removeDirectoryIfExists($shadow);

        foreach ((array)glob($this->tmpPath . $dir . '_*.zip') as $zip) {
            if (is_file($zip) && !@unlink($zip)) {
                throw new \RuntimeException('Failed to remove extension temporary archive');
            }
        }
        foreach ((array)glob($this->tmpPath . 'backup_' . $dir . '_*') as $backup) {
            $this->removeDirectoryIfExists($backup);
        }
    } */

    private function validatePackage(string $extensionPath, string $type, array $manifest): void
    {
        $configFile = $extensionPath . DIRECTORY_SEPARATOR . 'config.json';
        if (!is_file($configFile)) {
            throw new \RuntimeException('Extension package config.json is missing');
        }
        $config = SecurityHelper::jsonDecode((string)file_get_contents($configFile));
        if (!is_array($config)) {
            throw new \RuntimeException('Extension package config.json is invalid');
        }

        $expectedType = $type === 'theme' ? 2 : 1;
        if ((int)($config['type'] ?? 0) !== $expectedType) {
            throw new \RuntimeException('Extension package type does not match signed manifest');
        }
        if (!isset($config['version'])
            || !hash_equals((string)$manifest['version'], (string)$config['version'])) {
            throw new \RuntimeException('Extension package version does not match signed manifest');
        }

        $requiredSoftware = (string)($config['software_version'] ?? '');
        $currentSoftware = (string)($this->appConfig['version'] ?? '');
        if ($requiredSoftware === '' || $currentSoftware === ''
            || version_compare($currentSoftware, $requiredSoftware, '<')) {
            throw new \RuntimeException('Extension package requires a newer WellCMS version');
        }

        foreach (['dependencies', 'dependencies_theme'] as $dependencyField) {
            if (!isset($config[$dependencyField])) {
                continue;
            }
            if (!is_array($config[$dependencyField])) {
                throw new \RuntimeException('Extension package dependency declaration is invalid');
            }
            foreach ($config[$dependencyField] as $dependencyDir => $minimumVersion) {
                if (!is_string($dependencyDir)
                    || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dependencyDir)
                    || !is_string($minimumVersion)
                    || trim($minimumVersion) === ''
                    || strlen($minimumVersion) > 32) {
                    throw new \RuntimeException('Extension package dependency identity is invalid');
                }
            }
        }

        foreach (['install', 'upgrade', 'uninstall'] as $action) {
            if (!isset($config[$action])) {
                // 旧扩展使用固定文件名约定；文件存在即可由统一生命周期入口执行。
                continue;
            }
            $relative = is_array($config[$action]) ? (string)($config[$action]['file'] ?? '') : '';
            if (!$this->isSafeRelativePath($relative)
                || !is_file($extensionPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                throw new \RuntimeException('Invalid extension lifecycle script: ' . $action);
            }
        }
    }

    private function assertIdentity(string $dir, string $type, int $storeId): void
    {
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dir)
            || !in_array($type, ['plugin', 'theme'], true)
            || $storeId <= 0) {
            throw new \InvalidArgumentException('Invalid extension deployment identity');
        }
    }

    private function assertTransaction(array $transaction): void
    {
        foreach (['target_path', 'backup_path', 'stage_path', 'had_previous'] as $field) {
            if (!array_key_exists($field, $transaction)) {
                throw new \InvalidArgumentException('Invalid extension deployment transaction');
            }
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '\\') !== false
            || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Failed to create extension transaction directory');
        }
    }

    private function removeDirectoryIfExists(string $path): void
    {
        if (is_dir($path) && !DirectoryHelper::rmdirRecursive($path, true)) {
            throw new \RuntimeException('Failed to remove extension transaction directory: ' . basename($path));
        }
    }
}