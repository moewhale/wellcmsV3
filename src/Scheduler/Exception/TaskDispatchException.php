<?php

declare(strict_types=1);

namespace Framework\Scheduler\Exception;

/**
 * 调度基础设施投递阶段异常。
 *
 * 只携带确定性分类和 previous；对外数组文案由 TaskManage 固定生成。
 */
final class TaskDispatchException extends \RuntimeException
{
    /** @var string */
    private $outcome;

    public function __construct(string $outcome, ?\Throwable $previous = null)
    {
        if ($outcome !== \Framework\Scheduler\TaskDispatchResult::OUTCOME_NOT_SUBMITTED
            && $outcome !== \Framework\Scheduler\TaskDispatchResult::OUTCOME_UNKNOWN
        ) {
            throw new \InvalidArgumentException('Invalid task dispatch outcome');
        }

        $this->outcome = $outcome;
        parent::__construct('Task dispatch ' . $outcome, 0, $previous);
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }
}
