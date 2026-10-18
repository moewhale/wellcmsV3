<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Utils;

/**
 * 通用 HTML 解析工具
 * - detectEncoding(): 从 HTTP 头 / <meta> 猜测原始编码
 * - normalizeToUtf8(): 将 HTML 统一转为 UTF-8，并注入 <meta charset="utf-8">
 * - loadDom(): 安全加载为 DOMDocument
 * - fetchUrl(): 受 SSRF 边界保护的抓取，返回 [body, headers]
 * - absolutizeUrl(): 把相对链接转为绝对 URL（用于 favicon 等）
 */
trait HtmlParseHelper
{
    /**
     * 受 SSRF 边界保护的抓取。
     *
     * 自动重定向会绕过应用层目标校验，因此每一跳均重新解析、校验并固定 DNS。
     * 无 cURL 时安全能力不完整，选择显式失败，由调用方降级为空预览。
     *
     * @return array [string $body, array $headers]
     */
    protected function fetchUrl(string $url): array
    {
        $currentUrl = $url;
        $redirects = 0;
        $maxRedirects = 3;
        $maxBytes = 512 * 1024;

        while (true) {
            $target = $this->validatePreviewTarget($currentUrl);
            $lastFailure = null;
            foreach ($target['ips'] as $ip) {
                try {
                    [$status, $headers, $body] = $this->requestPreviewHop(
                        $currentUrl,
                        $target['host'],
                        $target['port'],
                        $ip,
                        $maxBytes
                    );
                    $lastFailure = null;
                    break;
                } catch (\RuntimeException $e) {
                    $lastFailure = $e;
                }
            }
            if ($lastFailure !== null) {
                throw $lastFailure;
            }

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->previewHeaderLine($headers, 'Location');
                if ($location === '') {
                    return [$body, $this->flattenPreviewHeaders($headers)];
                }
                if ($redirects >= $maxRedirects) {
                    throw new \RuntimeException('Too many redirects');
                }

                $currentUrl = $this->resolvePreviewRedirect($currentUrl, $location);
                $redirects++;
                continue;
            }

            return [$body, $this->flattenPreviewHeaders($headers)];
        }
    }

    /**
     * 校验一跳目标并返回用于 DNS pinning 的地址。
     *
     * @return array{host:string,port:int,ip:string,ips:array<int,string>}
     */
    protected function validatePreviewTarget(string $url): array
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid URL');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Protocol not allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URL credentials not allowed');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new \InvalidArgumentException('Port not allowed');
        }

        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === '') {
            throw new \InvalidArgumentException('Invalid URL');
        }

        $ips = array_values(array_unique($this->resolvePreviewHost($host)));
        if (empty($ips)) {
            throw new \InvalidArgumentException('DNS resolution failed');
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicPreviewIp($ip)) {
                throw new \InvalidArgumentException('Private or reserved IP not allowed');
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $ips[0],
            'ips' => $ips,
        ];
    }

    /**
     * 解析目标全部 A/AAAA 地址；任一结果不安全时由调用方整组拒绝。
     *
     * @return string[]
     */
    protected function resolvePreviewHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $dnsType = (defined('DNS_A') ? DNS_A : 0) | (defined('DNS_AAAA') ? DNS_AAAA : 0);
            $records = $dnsType ? @dns_get_record($host, $dnsType) : false;
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = (string)$record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = (string)$record['ipv6'];
                    }
                }
            }
        }

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        return array_values(array_unique(array_filter($ips, static function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP) !== false;
        })));
    }

    /**
     * 执行单跳请求；连接固定到已校验 IP，且在接收阶段限制响应体。
     *
     * @return array{0:int,1:array<string,array<int,string>>,2:string}
     */
    protected function requestPreviewHop(string $url, string $host, int $port, string $ip, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension required for secure preview');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL');
        }

        $headers = [];
        $body = '';
        $tooLarge = false;
        $pinnedIp = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

        try {
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // 必须直连已校验并固定的 IP。环境代理会自行解析目标域名，
                // 既使 CURLOPT_RESOLVE 失效，也会让 CURLINFO_PRIMARY_IP 变成代理 IP。
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . $browserUA,
                    'Accept: text/html, application/xhtml+xml',
                ],
                CURLOPT_ENCODING => '',
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $pinnedIp)],
                CURLOPT_DNS_CACHE_TIMEOUT => 0,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_HEADERFUNCTION => function ($resource, string $line) use (&$headers) {
                    $length = strlen($line);
                    $trimmed = trim($line);
                    if (stripos($trimmed, 'HTTP/') === 0) {
                        $headers = [];
                    } elseif ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                        [$name, $value] = explode(':', $trimmed, 2);
                        $name = strtolower(trim($name));
                        $headers[$name][] = trim($value);
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => function ($resource, string $chunk) use (&$body, &$tooLarge, $maxBytes) {
                    $length = strlen($chunk);
                    if (strlen($body) + $length > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return $length;
                },
            ];

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }

            curl_setopt_array($ch, $options);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);

            if ($tooLarge) {
                throw new \RuntimeException('Preview response exceeds 512 KiB');
            }
            if ($result === false || $status === 0) {
                throw new \RuntimeException($error !== '' ? $error : 'Preview network error');
            }
            if (!$this->isPublicPreviewIp($primaryIp) || !$this->samePreviewIp($primaryIp, $ip)) {
                throw new \RuntimeException('Connected IP validation failed');
            }

            return [$status, $headers, $body];
        } finally {
            curl_close($ch);
        }
    }

    protected function isPublicPreviewIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $blocked = strpos($ip, ':') === false
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
            : ['::/96', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

        foreach ($blocked as $cidr) {
            if ($this->previewIpInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    protected function previewIpInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }

        $prefix = (int)$prefixText;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
    }

    protected function samePreviewIp(string $left, string $right): bool
    {
        $packedLeft = @inet_pton($left);
        $packedRight = @inet_pton($right);
        return $packedLeft !== false && $packedRight !== false && hash_equals($packedLeft, $packedRight);
    }

    /**
     * 按当前 URL 解析 Location，并在下一轮重新执行完整目标校验。
     */
    protected function resolvePreviewRedirect(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\\x00-\\x20\\x7f\\\\]/', $location)) {
            throw new \InvalidArgumentException('Invalid redirect URL');
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            throw new \InvalidArgumentException('Invalid redirect base URL');
        }

        $host = (string)$base['host'];
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $origin = strtolower((string)$base['scheme']) . '://' . $host;
        if (isset($base['port'])) {
            $origin .= ':' . (int)$base['port'];
        }

        if (strpos($location, '//') === 0) {
            return strtolower((string)$base['scheme']) . ':' . $location;
        }

        $basePath = isset($base['path']) && $base['path'] !== '' ? (string)$base['path'] : '/';
        if ($location[0] === '?') {
            return $origin . $basePath . $location;
        }
        if ($location[0] === '#') {
            $withoutFragment = preg_replace('/#.*$/', '', $baseUrl);
            return $withoutFragment . $location;
        }

        $suffix = '';
        $path = $location;
        $suffixPos = strcspn($path, '?#');
        if ($suffixPos < strlen($path)) {
            $suffix = substr($path, $suffixPos);
            $path = substr($path, 0, $suffixPos);
        }
        if ($path === '' || $path[0] !== '/') {
            $directory = substr($basePath, 0, strrpos($basePath . '/', '/'));
            $path = $directory . '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $origin . '/' . implode('/', $segments) . $suffix;
    }

    /** @param array<string,array<int,string>> $headers */
    protected function previewHeaderLine(array $headers, string $name): string
    {
        $values = $headers[strtolower($name)] ?? $headers[$name] ?? [];
        if (!is_array($values)) {
            $values = [$values];
        }
        return isset($values[0]) ? trim((string)$values[0]) : '';
    }

    /** @param array<string,array<int,string>> $headers */
    protected function flattenPreviewHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            $result[] = $name . ': ' . implode(', ', $values);
        }
        return $result;
    }

    /**
     * 从响应头与 HTML <meta> 中猜测原始编码；默认回退 UTF-8
     */
    protected function detectEncoding(string $html, array $headers = []): string
    {
        // 1) 先从响应头找 charset
        foreach ($headers as $line) {
            if (stripos($line, 'content-type:') !== false && stripos($line, 'charset=') !== false) {
                $pos = stripos($line, 'charset=');
                if ($pos !== false) {
                    $enc = trim(substr($line, $pos + 8));
                    $enc = trim(str_replace([';', '"', "'"], '', $enc));
                    if ($enc !== '') return strtoupper($enc);
                }
            }
        }

        // 2) 再从 <meta charset=...> / <meta http-equiv=...> 中找
        //   使用正则快速匹配（无需先 load DOM，避免错误编码导致的乱码）
        if (preg_match('/<meta\s+charset=["\']?\s*([a-z0-9\-\_]+)\s*["\']?/i', $html, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/<meta\s+http-equiv=["\']content-type["\']\s+content=["\'][^"\']*charset=([a-z0-9\-\_]+)[^"\']*["\']/i', $html, $m)) {
            return strtoupper($m[1]);
        }

        // 3) 默认回退 UTF-8
        return 'UTF-8';
    }

    /**
     * 统一转为 UTF-8，并确保 <head> 里存在 <meta charset="UTF-8">
     */
    protected function normalizeToUtf8(string $html, string $sourceEncoding = 'UTF-8'): string
    {
        // 先把原始字节流转换为 HTML-ENTITIES（DOMDocument 最稳）
        // 如果源本身是 UTF-8，mb_convert_encoding 也安全
        $utf8Html = @mb_convert_encoding($html, 'HTML-ENTITIES', $sourceEncoding ?: 'UTF-8');

        // 注入/替换 meta charset，确保 DOMDocument 以 UTF-8 解读
        // 有 <head>：在 <head> 起始处插入；无 <head>：加一个
        if (stripos($utf8Html, '<head') !== false) {
            // 若已有 meta charset，尽量替换；否则插入
            if (preg_match('/<meta\s+charset=["\']?([a-z0-9\-\_]+)["\']?/i', $utf8Html)) {
                $utf8Html = preg_replace('/<meta\s+charset=["\']?([a-z0-9\-\_]+)["\']?/i', '<meta charset="UTF-8"', $utf8Html, 1);
            } else {
                $utf8Html = preg_replace('/<head([^>]*)>/i', '<head$1><meta charset="UTF-8">', $utf8Html, 1);
            }
        } else {
            // 粗暴兜底：包一层 <head>
            $utf8Html = '<head><meta charset="UTF-8"></head>' . $utf8Html;
        }

        return $utf8Html;
    }

    /**
     * 安全加载 HTML 为 DOMDocument（UTF-8）
     */
    protected function loadDom(string $html, array $headers = []): \DOMDocument
    {
        $enc = $this->detectEncoding($html, $headers);
        $normalized = $this->normalizeToUtf8($html, $enc);

        $doc = new \DOMDocument();
        // 避免警告：HTML 不完整/非法时不抛 warning
        @$doc->loadHTML($normalized, LIBXML_NOWARNING | LIBXML_NOERROR);
        return $doc;
    }

    /**
     * 将相对 URL 转为绝对 URL（简易版，够 favicon 等使用）
     */
    protected function absolutizeUrl(string $base, string $href): string
    {
        if ($href === '') return $base;
        // 已是绝对
        if (preg_match('~^(?:https?:)?//~i', $href)) return $href;

        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $href;

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $root = $scheme . '://' . $host . $port;

        if ($href[0] === '/') {
            return $root . $href;
        }

        // 相对路径：拼在 base path 后
        $path = isset($parts['path']) ? $parts['path'] : '/';
        // 去掉文件名，保留目录
        $path = rtrim(substr($path, 0, strrpos($path . '/', '/')), '/') . '/';
        return $root . $path . $href;
    }

    /**
     * 从 HTML 中提取标题
     *
     * 优先提取 <title>，兜底 Open Graph / Twitter Card 元标签
     *
     * @param string $html 页面 HTML
     * @return string 标题（纯文本，已 trim），找不到返回空字符串
     */
    protected function extractTitle(string $html): string
    {
        if ($html === '') return '';

        // 1. <title> 标签
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        // 2. Open Graph og:title（JS 渲染站点通常服务端输出此标签）
        if (preg_match('/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/si', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\']\s+[^>]*property=["\']og:title["\']/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        // 3. Twitter Card twitter:title
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\']/si', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\']\s+[^>]*name=["\']twitter:title["\']/si', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') return $title;
        }

        return '';
    }

    /**
     * 从 HTML 中提取 favicon URL
     *
     * 查找优先级：
     * 1. <link rel="icon" href="...">
     * 2. <link rel="shortcut icon" href="...">
     * 3. <link rel="apple-touch-icon" href="...">
     * 4. 回退：{pageUrl}/favicon.ico
     *
     * @param string $html    页面 HTML
     * @param string $pageUrl 页面完整 URL（用于解析相对路径 + 回退）
     * @return string favicon 绝对 URL，找不到返回空字符串
     */
    protected function extractFavicon(string $html, string $pageUrl): string
    {
        $patterns = [
            // 标准 favicon + shortcut icon
            '/<link[^>]+rel=["\']?(?:shortcut\s+)?icon["\']?[^>]+href=["\']([^"\']+)["\']/si',
            // apple-touch-icon
            '/<link[^>]+rel=["\']?apple-touch-icon["\']?[^>]+href=["\']([^"\']+)["\']/si',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $favicon = trim($m[1]);
                if ($favicon !== '') {
                    return $this->absolutizeUrl($pageUrl, $favicon);
                }
            }
        }

        // 回退：默认 favicon 路径
        $default = rtrim($pageUrl, '/') . '/favicon.ico';
        return $default;
    }
}
