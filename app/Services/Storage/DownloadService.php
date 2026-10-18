<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Framework\Exception\Http\ForbiddenException;
use Framework\Http\Interfaces\ServerRequestInterface;
use Framework\Session\SessionInterface;

/** Session 绑定下载；只持有容器与站点配置，不保存请求、用户或签名结果。 */
class DownloadService
{
    private $container;
    private $appConfig;
    private $uploadConfig;

    public function __construct(\Framework\Core\Container $container)
    {
        $this->container = $container;
        $this->appConfig = $container->get('appConfig');
        $this->uploadConfig = $container->get('uploadConfig');
    }

    /** 目标必须来自已授权附件；只计算查询参数，最终 URL 由调用方的主程序 URL 生成器生成。 */
    public function createQueries(array $targets, SessionInterface $session, int $now, int $expiresLimit = 0): array
    {
        $key = $this->signingKey();
        $sessionId = $session->getId();
        if ($sessionId === '') throw new \InvalidArgumentException('Download requires a session');
        $ttl = $this->positiveInt($this->uploadConfig['download']['expire'] ?? null);
        if ($now < 0 || $ttl > PHP_INT_MAX - $now || $expiresLimit < 0) {
            throw new \InvalidArgumentException('Invalid download lifetime');
        }
        $expiresAt = $expiresLimit > 0 ? min($now + $ttl, $expiresLimit) : $now + $ttl;
        if ($expiresAt <= $now) throw new \InvalidArgumentException('Download authorization expired');
        $queries = $aliases = $signatures = [];
        foreach ($targets as $index => $target) {
            $id = $this->positiveInt($target['attachmentId'] ?? null);
            // 签名输入只包含身份和业务参数；不再生成、接收或解析中间下载 URL。
            if (array_key_exists('url', $target)) throw new \InvalidArgumentException('Download signing requires business parameters, not a URL');
            $query = array_key_exists('query', $target) ? $target['query'] : [];
            if (!is_array($query)) throw new \InvalidArgumentException('Invalid download query');
            foreach ($query as $name => &$value) {
                if (!is_string($name) || is_numeric($name) || !preg_match('/^[^\x00.\[\]\s]+$/D', $name)
                    || (!is_string($value) && !is_int($value))) {
                    throw new \InvalidArgumentException('Download query must contain named string values');
                }
                $value = (string)$value;
            }
            unset($value);
            ksort($query, SORT_STRING);
            if (array_key_exists('downloadExpires', $query) || array_key_exists('downloadSignature', $query)) {
                throw new \InvalidArgumentException('Download target must be stable');
            }
            $message = $this->message($id, $query, $expiresAt, $sessionId);
            if (!isset($signatures[$message])) $signatures[$message] = hash_hmac('sha256', $message, $key);
            $queries[$index] = $query + ['downloadExpires' => $expiresAt, 'downloadSignature' => $signatures[$message]];
            foreach (($target['aliases'] ?? []) as $alias) {
                if (!is_string($alias) || $alias === '') throw new \InvalidArgumentException('Invalid download alias');
                if (isset($aliases[$alias]) && $queries[$aliases[$alias]] !== $queries[$index]) {
                    throw new \InvalidArgumentException('Conflicting download alias');
                }
                $aliases[$alias] = $index;
            }
        }
        return ['queries' => $queries, 'aliases' => $aliases, 'expiresAt' => $expiresAt];
    }

    public function verify(ServerRequestInterface $request, int $now): void
    {
        $key = $this->signingKey();
        try {
            $id = $this->positiveInt($request->getAttribute('id'));
            $query = $this->query($request->getUri()->getQuery());
            $processed = $request->getQueryParams();
            // 只排除 RequestProcessor 明确注入且值一致的数字路由项。
            foreach ($request->getAttribute('route_params', []) as $index => $value) {
                if (!is_int($index) || !array_key_exists($index, $processed) || $processed[$index] !== $value) {
                    throw new \InvalidArgumentException('Inconsistent download route');
                }
                unset($processed[$index]);
            }
            ksort($processed, SORT_STRING);
            if ($processed !== $query) throw new \InvalidArgumentException('Inconsistent download query');
            if ($request->getUri()->getPath() === '' || $request->getUri()->getPath() === '/') {
                $query = $this->withoutQueryRoute($query, $request->getUri()->getQuery());
            }
            $expiresAt = $this->positiveInt($query['downloadExpires'] ?? null);
            $signature = $query['downloadSignature'] ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $signature) || $expiresAt <= $now) {
                throw new \InvalidArgumentException('Invalid download signature');
            }
            $session = $request->getAttribute(SessionInterface::class);
            if (!$session instanceof SessionInterface || $session->getId() === '') {
                throw new \InvalidArgumentException('Download requires a session');
            }
            unset($query['downloadExpires'], $query['downloadSignature']);
            $expected = hash_hmac('sha256', $this->message($id, $query, $expiresAt, $session->getId()), $key);
            if (!hash_equals($expected, $signature)) throw new \InvalidArgumentException('Invalid download signature');
        } catch (\InvalidArgumentException $error) {
            throw new ForbiddenException('Invalid or expired download link');
        }
    }

    /** 上传链路保护：仅比较已授权准确别名；不以签名代替附件授权或验证保存时效。 */
    public function referenceKey(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['fragment'])) return '';
        try {
            $query = $this->query($parts['query'] ?? '');
            if (($parts['path'] ?? '') === '' || $parts['path'] === '/') $query = $this->withoutQueryRoute($query, $parts['query'] ?? '');
            if (array_key_exists('downloadExpires', $query) || array_key_exists('downloadSignature', $query)) {
                $this->positiveInt($query['downloadExpires'] ?? null);
                if (!preg_match('/^[a-f0-9]{64}$/D', $query['downloadSignature'] ?? '')) return '';
                unset($query['downloadExpires'], $query['downloadSignature']);
            }
        } catch (\InvalidArgumentException $error) {
            return '';
        }
        $base = explode('?', $url, 2)[0];
        // 上传链路保护：本站地址才可匹配附件身份；不忽略外站、端口、fragment 或额外业务参数。
        $domain = rtrim((string)($this->appConfig['domain'] ?? ''), '/');
        if ($domain === '') $domain = \App\Utils\HttpLink::httpUrl();
        if ($base === $domain || strpos($base, $domain . '/') === 0) $base = substr($base, strlen($domain));
        $relative = strpos($base, '//') !== 0 && strpos($base, ':') === false;
        $route = $relative ? \Framework\Http\Middleware\RequestProcessorMiddleware::routeParts(
            $base === '' ? '/' : $base, $parts['query'] ?? '', (int)($this->appConfig['url_rewrite_on'] ?? 3)) : [];
        // 插件只处理自身 query／路由上下文；不签发、不改变授权附件集合。
        $normalize = true;
        // hook app_Services_Storage_DownloadService_referenceQuery.php
        if (!$normalize) return '';
        ksort($query, SORT_STRING);
        $suffix = $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        if (count($route) === 2 && $route[0] === 'download') {
            try { $id = $this->positiveInt($route[1]); } catch (\InvalidArgumentException $error) { return ''; }
            return 'attachment:' . $id . $suffix;
        }
        return 'url:' . ($relative ? ltrim($base, '/') : $base) . $suffix;
    }

    private function signingKey(): string
    {
        $key = $this->appConfig['auth_key'] ?? null;
        if (!is_string($key) || $key === '') throw new \RuntimeException('Download signing key is not configured');
        return $key;
    }

    private function positiveInt($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('Invalid download integer');
        }
        return (int)$value;
    }

    private function query(string $raw): array
    {
        parse_str($raw, $query);
        foreach ($query as $name => $value) {
            if (!is_string($name) || is_numeric($name) || !is_string($value)) {
                throw new \InvalidArgumentException('Download query must contain named string values');
            }
        }
        ksort($query, SORT_STRING);
        return $query;
    }

    /** 模式 0 的裸首段属于路由；保留后续具名参数，避免把路由重复交给 URL 生成器。 */
    private function withoutQueryRoute(array $query, string $raw): array
    {
        $first = explode('&', $raw, 2)[0];
        if ($first === '' || strpos($first, '=') !== false) return $query;
        parse_str($first, $route);
        foreach ($route as $name => $value) {
            if (($query[$name] ?? null) === $value) unset($query[$name]);
        }
        return $query;
    }

    private function message(int $id, array $query, int $expiresAt, string $sessionId): string
    {
        $fields = ['wellcms.download.v1', (string)$id, (string)count($query)];
        foreach ($query as $name => $value) { $fields[] = $name; $fields[] = $value; }
        $fields[] = (string)$expiresAt;
        $fields[] = $sessionId;
        $message = '';
        foreach ($fields as $value) $message .= strlen($value) . ':' . $value;
        return $message;
    }
}
