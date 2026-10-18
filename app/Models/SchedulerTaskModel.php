<?php

declare(strict_types=1);

namespace App\Models;

/**
 * 调度任务数据模型
 *
 * 对应 install.sql 核心表 `well_scheduler_tasks`。
 * 继承 BaseModel 获得标准 CRUD：insert()、update()、read()、find()、delete()、count()。
 *
 * PHP 7.2 兼容。
 */
class SchedulerTaskModel extends BaseModel
{
    /** @var string */
    protected $table = 'scheduler_tasks';

    /** 状态写入返回零行时从同一主库核验，不能用延迟副本伪造持久化成功。 */
    public function retainedFailuresOnMaster(int $limit = 100): array
    {
        $limit = min(100, max(1, $limit));
        $table = $this->db->prefix . $this->getTable();
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new \LogicException('Invalid task table');
        $shard = $this->db->resolveShard($this->getTable());
        $read = function () use ($table, $shard, $limit): array {
            $statement = $this->db->connect('master', $shard)->prepare('SELECT * FROM `' . $table . '` WHERE `status` = ? ORDER BY `scheduled_at` DESC LIMIT ' . $limit);
            try { $statement->execute([\Framework\Scheduler\Task::STATUS_FAILED]); return $statement->fetchAll(\PDO::FETCH_ASSOC); }
            finally { $statement->closeCursor(); }
        };
        return $this->db->inTransaction($shard) ? $read() : $this->db->transaction($read, 1, $shard);
    }

    public function readOnMaster(string $id, array $fields = ['*']): array
    {
        return $this->readFromMaster(['id' => $id], $fields);
    }
}
