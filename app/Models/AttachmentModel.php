<?php

namespace App\Models;

class AttachmentModel extends BaseModel
{
    /** @var string */
    protected $table = 'attachment';

    /** 上传链路保护：2026-11-06 业主明确批准三数据库修复；保持归属白名单、IN/游标交集及主库事务，复用原方言，不改绑定/解除流程。 */
    public function findOnMaster(array $condition, int $afterId = 0, int $limit = 100): array
    {
        $table = $this->db->prefix . $this->table;
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table) || $limit < 1 || $limit > 1000 || !$condition) throw new \InvalidArgumentException('Invalid attachment master query');
        foreach ($condition as $column => $value) {
            if (!in_array($column, ['id', 'module', 'storage_id', 'target_id', 'reply_id', 'user_id'], true)) throw new \InvalidArgumentException('Invalid attachment identity column');
            $values = array_values((array)$value);
            if (!$values) return [];
            foreach ($values as $item) {
                if ((!is_int($item) && !(is_string($item) && ctype_digit($item))) || (int)$item < 0) throw new \InvalidArgumentException('Invalid attachment identity value');
            }
            $condition[$column] = !is_array($value) && count($values) === 1 ? $values[0] : $values;
        }
        $shard = $this->db->resolveShard($this->table, $condition);
        // 限定同一原表列保留 id IN (...) 与 id > cursor 的交集；不覆盖已有 id 条件或截断整数。
        $condition[$table . '.id'] = ['>' => $afterId];
        $read = function () use ($condition, $limit): array {
            return $this->db->query($this->table, $condition, ['id' => 1], 1, $limit);
        };
        return $this->db->inTransaction($shard) ? $read() : $this->db->transaction($read, 1, $shard);
    }
}
