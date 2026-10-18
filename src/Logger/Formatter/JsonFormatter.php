<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Logger\Formatter;

/**
 * 将日志记录格式化为 JSON 字符串
 */
class JsonFormatter
{
    /**
     * 格式化日志行
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return string  一行 JSON 字符串
     */
    public function format(string $level, string $message, array $context = []): string
    {
        $timestamp = (new \DateTime())->format('c');
        $entry = [
            'timestamp' => $timestamp,
            'level'     => $level,
            'message'   => $this->interpolate($message, $context),
            'context'   => $this->normalizeContext($context),
        ];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        try {
            $json = json_encode($entry, $flags);
        } catch (\Throwable $e) {
            $entry['context'] = ['encoding_error' => get_class($e)];
            $json = json_encode($entry, $flags);
        }
        if ($json === false) {
            $entry['context'] = ['encoding_error' => json_last_error_msg()];
            $json = json_encode($entry, $flags);
        }
        return $json . "\r\n\r\n";
    }

    /** 定位异常时只保留有限调用帧，不记录参数及对象。 */
    public static function exceptionContext(\Throwable $exception, int $depth = 0): array
    {
        if ($depth >= 3) {
            return ['type' => get_class($exception), 'truncated' => true];
        }

        $hasPathHelper = class_exists(\App\Utils\PathHelper::class);
        $context = [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $hasPathHelper ? \App\Utils\PathHelper::relative($exception->getFile()) : $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => [],
        ];
        if ($exception instanceof \Framework\Exception\Infra\PdoConnectionException) {
            $context['database_connection'] = $exception->getConnectionContext();
        }
        $frameFields = ['file' => true, 'line' => true, 'function' => true, 'class' => true, 'type' => true];
        foreach ($exception->getTrace() as $frame) {
            if (count($context['trace']) >= 20) {
                $context['trace_truncated'] = true;
                break;
            }
            $safe = array_intersect_key($frame, $frameFields);
            if ($hasPathHelper && isset($safe['file'])) {
                $safe['file'] = \App\Utils\PathHelper::relative($safe['file']);
            }
            $context['trace'][] = $safe;
        }
        if ($exception->getPrevious() !== null) {
            $context['previous'] = self::exceptionContext($exception->getPrevious(), $depth + 1);
        }
        return $context;
    }

    private function normalizeContext($value, int $depth = 0)
    {
        if ($value instanceof \Throwable) return self::exceptionContext($value);
        if (!is_array($value)) return $value;
        if ($depth >= 8) return '[truncated]';

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeContext($item, $depth + 1);
        }
        return $normalized;
    }

    /**
     * 将上下文 placeholder 替换到消息中
     */
    protected function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string)$val;
            } else {
                $replace['{' . $key . '}'] = '[' . gettype($val) . ']';
            }
        }
        return strtr($message, $replace);
    }
}
