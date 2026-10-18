<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

/**
 * Downloader
 *
 * 负责安全下载升级包并验证完整性
 */
class Downloader
{
    private const MAX_REDIRECTS = 5;
    private const MAX_DOWNLOAD_BYTES = 536870912;

    /**
     * 下载远程文件并校验哈希
     *
     * @param string $url 官方下载地址
     * @param string $savePath 本地保存路径
     * @param string $expectedHash 受信清单中的 SHA-256（必填；默认值仅保留旧调用签名兼容）
     * @return bool
     * @throws \RuntimeException
     */
    public function download(string $url, string $savePath, string $expectedHash = ''): bool
    {
        $remote = null;
        $local = null;
        $createdLocal = false;

        try {
            $expectedHash = strtolower(trim($expectedHash));
            if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
                throw new \InvalidArgumentException('A trusted SHA-256 package hash is required');
            }

            $currentUrl = $this->assertHttpsUrl($url);
            for ($redirects = 0; ; $redirects++) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 600,
                        'header' => implode("\r\n", [
                            'User-Agent: ' . \Framework\Utils\IpHelper::userAgent(),
                            'Accept: application/zip, application/octet-stream',
                            'Connection: close',
                        ]),
                        'ignore_errors' => true,
                        'follow_location' => false,
                        'max_redirects' => 0,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        'SNI_enabled' => true,
                    ],
                ]);

                $remote = @fopen($currentUrl, 'rb', false, $context);
                if (!is_resource($remote)) {
                    $error = error_get_last();
                    throw new \RuntimeException(
                        'Failed to open verified HTTPS URL: ' . ($error['message'] ?? 'network error')
                    );
                }

                $meta = stream_get_meta_data($remote);
                $response = $this->parseResponseHeaders((array)($meta['wrapper_data'] ?? []));
                $statusCode = $response['status'];
                if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                    fclose($remote);
                    $remote = null;
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new \RuntimeException('Package download redirect limit exceeded');
                    }
                    if ($response['location'] === '') {
                        throw new \RuntimeException('Package download redirect has no Location header');
                    }
                    $currentUrl = $this->resolveRedirectUrl($currentUrl, $response['location']);
                    continue;
                }
                if ($statusCode !== 200) {
                    throw new \RuntimeException('HTTP error ' . $statusCode . ' while downloading package');
                }
                if ($response['content_length'] > self::MAX_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('Package exceeds the maximum download size');
                }
                break;
            }

            // 不覆盖已有本地文件，异常清理也只删除本次创建的文件。
            $local = @fopen($savePath, 'xb');
            if (!$local) {
                throw new \RuntimeException('Failed to open local path for writing: ' . $savePath);
            }
            $createdLocal = true;

            $hashCtx = hash_init('sha256');
            $bufferSize = 256 * 1024;
            $downloaded = 0;

            while (!feof($remote)) {
                $chunk = fread($remote, $bufferSize);
                if ($chunk === false) {
                    throw new \RuntimeException('Error occurred during stream reading.');
                }
                if ($chunk === '') continue;

                $chunkLength = strlen($chunk);
                $downloaded += $chunkLength;
                if ($downloaded > self::MAX_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('Package exceeds the maximum download size');
                }
                $written = 0;
                while ($written < $chunkLength) {
                    $bytes = fwrite($local, substr($chunk, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new \RuntimeException('Failed to write complete chunk to local disk.');
                    }
                    $written += $bytes;
                }

                hash_update($hashCtx, $chunk);
            }

            fclose($remote);
            $remote = null;
            fclose($local);
            $local = null;

            $actualHash = hash_final($hashCtx);
            if (!hash_equals($expectedHash, strtolower($actualHash))) {
                throw new \RuntimeException('Package SHA-256 verification failed');
            }

            return true;
        } catch (\Throwable $e) {
            if (is_resource($remote)) fclose($remote);
            if (is_resource($local)) fclose($local);
            if ($createdLocal && is_file($savePath)) @unlink($savePath);
            throw new \RuntimeException('Secure package download failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertHttpsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096 || strpos($url, "\0") !== false
            || preg_match('/[\r\n]/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Package URL is invalid');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Package URL must be credential-free HTTPS');
        }
        return $url;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '' || strpos($location, "\0") !== false || preg_match('/[\r\n]/', $location)) {
            throw new \RuntimeException('Package redirect URL is invalid');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $this->assertHttpsUrl($location);
        }
        if (strpos($location, '//') === 0) {
            return $this->assertHttpsUrl('https:' . $location);
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            throw new \RuntimeException('Package redirect base URL is invalid');
        }
        $host = (string)$base['host'];
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $authority = 'https://' . $host . (isset($base['port']) ? ':' . (int)$base['port'] : '');

        if ($location[0] === '?') {
            $path = (string)($base['path'] ?? '/');
            return $this->assertHttpsUrl($authority . $path . $location);
        }

        $locationParts = parse_url($location);
        if ($locationParts === false) {
            throw new \RuntimeException('Package redirect URL cannot be parsed');
        }
        $relativePath = (string)($locationParts['path'] ?? '');
        if ($relativePath === '' || $relativePath[0] !== '/') {
            $basePath = (string)($base['path'] ?? '/');
            $relativePath = rtrim(str_replace('\\', '/', dirname($basePath)), '/') . '/' . $relativePath;
        }
        $path = $this->normalizeUrlPath($relativePath);
        $query = isset($locationParts['query']) ? '?' . $locationParts['query'] : '';
        return $this->assertHttpsUrl($authority . $path . $query);
    }

    private function normalizeUrlPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') continue;
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    private function parseResponseHeaders(array $headers): array
    {
        $status = 0;
        $location = '';
        $contentLength = 0;
        foreach ($headers as $header) {
            $header = (string)$header;
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
                $status = (int)$matches[1];
                $location = '';
                $contentLength = 0;
                continue;
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            } elseif (stripos($header, 'Content-Length:') === 0) {
                $value = trim(substr($header, 15));
                $contentLength = ctype_digit($value) ? (int)$value : 0;
            }
        }
        return [
            'status' => $status,
            'location' => $location,
            'content_length' => $contentLength,
        ];
    }
}
