<?php

namespace App\Models;

class AttachmentModel extends BaseModel
{
    /** @var string */
    protected $table = 'attachment';

    /** 上传链路保护：只接受附件归属列，主库游标读取用于绑定/解除及副本复核。 */
    public function findOnMaster(array $condition, int $afterId = 0, int $limit = 100): array
    {
        $table = $this->db->prefix . $this->table;
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table) || $limit < 1 || $limit > 1000 || !$condition) {
            throw new \InvalidArgumentException('Invalid attachment master query');
        }
        $where = [];
        $parameters = [];
        foreach ($condition as $column => $value) {
            if (!in_array($column, ['id', 'module', 'storage_id', 'target_id', 'reply_id', 'user_id'], true)) {
                throw new \InvalidArgumentException('Invalid attachment identity column');
            }
            $values = array_values((array)$value);
            if (!$values) return [];
            foreach ($values as $item) {
                if ((!is_int($item) && !(is_string($item) && ctype_digit($item))) || (int)$item < 0) {
                    throw new \InvalidArgumentException('Invalid attachment identity value');
                }
            }
            $where[] = '`' . $column . '` IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            $parameters = array_merge($parameters, $values);
        }
        $where[] = '`id` > ?';
        $parameters[] = $afterId;
        $shard = $this->db->resolveShard($this->table, $condition);
        $read = function () use ($table, $shard, $where, $parameters, $limit): array {
            $statement = $this->db->connect('master', $shard)->prepare(
                'SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY `id` ASC LIMIT ' . $limit
            );
            try {
                $statement->execute($parameters);
                return $statement->fetchAll(\PDO::FETCH_ASSOC);
            } finally {
                $statement->closeCursor();
            }
        };
        return $this->db->inTransaction($shard) ? $read() : $this->db->transaction($read, 1, $shard);
    }
}
