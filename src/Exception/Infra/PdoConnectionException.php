<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/** 保持 PDO 异常契约，仅为物理连接创建失败补充可公开的定位字段。 */
final class PdoConnectionException extends \PDOException
{
    /** @var array */
    private $connectionContext;

    public function __construct(\PDOException $previous, array $context)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
        // PDO 的 code 可能为 SQLSTATE 字符串，不能传给要求 int 的异常构造函数。
        $this->code = $previous->getCode();
        $this->errorInfo = $previous->errorInfo;

        $this->connectionContext = ['phase' => 'connect'];
        foreach (['driver', 'role', 'shard', 'node_id'] as $field) {
            $value = $context[$field] ?? null;
            $this->connectionContext[$field] = is_string($value) || is_int($value) ? (string)$value : 'unknown';
        }
        if (!in_array($this->connectionContext['role'], ['master', 'slave'], true)) {
            $this->connectionContext['role'] = 'unknown';
        }

        $info = is_array($previous->errorInfo) ? $previous->errorInfo : [];
        $state = $info[0] ?? $previous->getCode();
        $state = is_string($state) && preg_match('/^[A-Z0-9]{5}$/D', $state) === 1 ? $state : null;
        if ($state === null && preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/', $previous->getMessage(), $matches) === 1) {
            $state = $matches[1];
        }
        $nativeCode = $previous->getCode();
        $driverCode = $info[1] ?? (is_int($nativeCode) ? $nativeCode : null);
        $driverCode = is_int($driverCode) || (is_string($driverCode) && preg_match('/^[0-9]+$/D', $driverCode) === 1) ? (int)$driverCode : null;
        if (($driverCode === null || $driverCode === 0)
            && preg_match('/SQLSTATE\[[A-Z0-9]{5}\]\s*\[(\d+)\]/', $previous->getMessage(), $matches) === 1) {
            $driverCode = (int)$matches[1];
        }
        $this->connectionContext['sqlstate'] = $state;
        $this->connectionContext['driver_code'] = $driverCode;
    }

    public function getConnectionContext(): array
    {
        return $this->connectionContext;
    }
}
