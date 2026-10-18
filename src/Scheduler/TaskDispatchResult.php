<?php

declare(strict_types=1);

namespace Framework\Scheduler;

/**
 * 调度投递数组协议的唯一解析边界。
 *
 * 旧结果或缺失字段一律按 unknown 失败闭合，调用方不得从 msg 猜测状态。
 */
final class TaskDispatchResult
{
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_NOT_SUBMITTED = 'not_submitted';
    public const OUTCOME_UNKNOWN = 'unknown';

    public static function isAccepted(array $result): bool
    {
        $status = $result['status'] ?? '';
        return ($status === 'success' || $status === 'duplicate')
            && ($result['outcome'] ?? '') === self::OUTCOME_ACCEPTED
            && self::taskId($result) !== null;
    }

    public static function outcome(array $result): string
    {
        if (self::isAccepted($result)) {
            return self::OUTCOME_ACCEPTED;
        }

        if (($result['status'] ?? '') !== 'error') {
            return self::OUTCOME_UNKNOWN;
        }

        return ($result['outcome'] ?? '') === self::OUTCOME_NOT_SUBMITTED
            ? self::OUTCOME_NOT_SUBMITTED
            : self::OUTCOME_UNKNOWN;
    }

    public static function taskId(array $result): ?string
    {
        $taskId = $result['taskId'] ?? null;
        if (!is_string($taskId) || trim($taskId) === '') {
            return null;
        }
        return $taskId;
    }

    public static function code(array $result): ?string
    {
        $code = $result['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return null;
        }
        return $code;
    }

    public static function isUnknown(array $result): bool
    {
        return self::outcome($result) === self::OUTCOME_UNKNOWN;
    }
}
