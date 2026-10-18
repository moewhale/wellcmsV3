<?php

declare(strict_types=1);

namespace App\Core;

use Framework\Database\Collector\QueryCollector;
use Framework\Http\Interfaces\ResponseInterface;
use Framework\Http\Psr7\Factories\StreamFactory;
use Framework\Utils\SafeHelper;

/** 刷新内置页脚的调试快照，覆盖模板渲染之后的会话等 SQL。 */
class DebugSqlRenderer
{
    public static function render(ResponseInterface $response): ResponseInterface
    {
        if (!defined('DEBUG') || \DEBUG <= 1
            || strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0])) !== 'text/html'
            || $response->hasHeader('Content-Encoding')
            || $response->hasHeader('Content-Disposition')) {
            return $response;
        }

        $body = $response->getBody();
        if (!$body->isReadable() || !$body->isSeekable()) {
            return $response;
        }

        $position = $body->tell();
        $html = (string)$body;
        $body->seek($position);
        if (strpos($html, '<!--wellcms-debug-sql:start-->') === false) {
            return $response;
        }

        $queries = QueryCollector::getLoggedQueries();
        $items = '';
        foreach ($queries as $query) {
            $items .= '<li>' . SafeHelper::txtToHtml($query) . '</li>';
        }
        $replacements = ['sql' => $items, 'count' => (string)count($queries)];
        $html = preg_replace_callback(
            '~<!--wellcms-debug-(sql|count):start-->.*?<!--wellcms-debug-\1:end-->~s',
            static function (array $match) use ($replacements): string {
                return $replacements[$match[1]];
            },
            $html
        );
        if ($html === null) {
            return $response;
        }

        $response = $response->withBody(StreamFactory::getInstance()->createStream($html));
        // 替换正文后不能保留旧的长度或内容校验值。
        $response = $response->withoutHeader('ETag')->withoutHeader('Content-MD5');
        return $response->hasHeader('Content-Length')
            ? $response->withHeader('Content-Length', (string)strlen($html))
            : $response;
    }
}
