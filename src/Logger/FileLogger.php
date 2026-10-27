<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Logger;

use Framework\Logger\LogLevel;

/**
 * 文件日志：FPM 在请求结束时批量写出，CLI/Swoole 沿原 flush 及时写出。
 */
class FileLogger implements \Framework\Logger\LoggerInterface
{
    /**
     * @var string 日志文件路径
     */
    protected $logFile;
    /**
     * @var array 日志缓冲区
     */
    protected $buffer = [];
    /**
     * @var \Framework\Logger\Formatter\JsonFormatter 日志格式化器
     */
    protected $formatter;

    public function __construct(array $loggerConfig = [])
    {
        $path = isset($loggerConfig['path']) ? $loggerConfig['path'] : sys_get_temp_dir();
        // 防御：确保日志路径是文件路径，尾部无分隔符（防止误传入目录路径）
        $this->logFile = rtrim($path, DIRECTORY_SEPARATOR);
        $this->formatter = new \Framework\Logger\Formatter\JsonFormatter();
        // 注册脚本结束时写入日志
        register_shutdown_function([$this, 'flush']);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->buffer[] = $this->formatter->format($level, $message, $context);
        // 常驻 CLI/Swoole 不以单次请求结束退出进程，不能一直等待 shutdown。
        if (PHP_SAPI === 'cli') $this->flush();
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL,  $message, $context);
    }
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING,   $message, $context);
    }
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE,    $message, $context);
    }
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * 实际写入日志文件
     */
    public function flush(): void
    {
        if (empty($this->buffer)) return;

        // 文件 I/O 可能让出协程；先移出本批，不能清掉期间其他请求新增的记录。
        $buffer = $this->buffer;
        $this->buffer = [];

        try {
            $logDir = dirname($this->logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            file_put_contents(
                $this->logFile,
                implode('', $buffer),
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'FileLogger flush to %s failed: %s',
                $this->logFile,
                $e->getMessage()
            ));
            foreach ($buffer as $line) {
                error_log(trim($line));
            }
        }
    }
}
