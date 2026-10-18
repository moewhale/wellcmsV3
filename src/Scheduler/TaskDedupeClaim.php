<?php

declare(strict_types=1);

namespace Framework\Scheduler;

/**
 * Scheduler dedupe claim 的唯一版本化编解码器。
 */
final class TaskDedupeClaim
{
    private const VERSION = 1;

    public static function encode(string $taskId, int $claimedAt): string
    {
        if ($taskId === '' || $claimedAt <= 0) {
            throw new \InvalidArgumentException('Invalid scheduler dedupe claim');
        }

        $encoded = json_encode([
            'v' => self::VERSION,
            'taskId' => $taskId,
            'claimedAt' => $claimedAt,
        ], JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode scheduler dedupe claim');
        }
        return $encoded;
    }

    public static function decode($claim): ?array
    {
        $decoded = is_array($claim) ? $claim : json_decode((string)$claim, true);
        if (!is_array($decoded)
            || count($decoded) !== 3
            || ($decoded['v'] ?? null) !== self::VERSION
            || !isset($decoded['taskId'], $decoded['claimedAt'])
            || !is_string($decoded['taskId'])
            || $decoded['taskId'] === ''
            || !is_int($decoded['claimedAt'])
            || $decoded['claimedAt'] <= 0
        ) {
            return null;
        }

        return [
            'taskId' => $decoded['taskId'],
            'claimedAt' => $decoded['claimedAt'],
        ];
    }

    public static function isLegacy($claim): bool
    {
        return $claim === '1' || $claim === 1;
    }
}
