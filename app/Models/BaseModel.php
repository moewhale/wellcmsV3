<?php

namespace App\Models;

use Framework\Database\Interfaces\DatabaseInterface;

/**
 * 基础数据模型
 * 
 * 提取所有 Model 的公共 CRUD 逻辑，减少重复代码。
 * 子类只需设置 $table 属性即可拥有完整的数据库操作能力。
 */
class BaseModel
{
    /**
     * @var DatabaseInterface
     */
    protected $db;

    /**
     * 数据表名（不含前缀）
     * 子类必须设置此属性
     *
     * @var string
     */
    protected $table;

    /**
     * 构造函数
     *
     * @param DatabaseInterface $db
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 获取数据表名
     *
     * @return string
     * @throws \RuntimeException
     */
    protected function getTable(): string
    {
        if (empty($this->table)) {
            throw new \RuntimeException('Property $table must be set in ' . static::class);
        }
        return $this->table;
    }

    /**
     * 插入单条记录
     *
     * @param array $data
     * @return mixed
     */
    public function insert(array $data = [])
    {
        return $this->db->insert($this->getTable(), $data);
    }

    /**
     * 更新记录
     *
     * @param array $condition
     * @param array $update
     * @return int
     */
    public function update(array $condition = [], array $update = []): int
    {
        return $this->db->update($this->getTable(), $condition, $update);
    }

    /**
     * 查询单条记录
     *
     * @param array $condition
     * @param array $orderBy
     * @param array $fields
     * @return array
     */
    public function read(array $condition = [], array $orderBy = [], array $fields = ['*']): array
    {
        return $this->db->queryOne($this->getTable(), $condition, $orderBy, $fields);
    }

    /** 上传链路保护：仅由明确需要主库事实的写入边界调用，不改变普通 read 的角色。 */
    public function readFromMaster(array $condition, array $fields = ['*']): array
    {
        $table = $this->db->prefix . $this->getTable();
        if (!$condition || !preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new \InvalidArgumentException('Invalid authoritative record locator');
        }
        $where = [];
        foreach ($condition as $column => $value) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $column) || !is_scalar($value)) {
                throw new \InvalidArgumentException('Authoritative reads require exact scalar locators');
            }
            $where[] = '`' . $column . '` = ?';
        }
        $select = [];
        foreach ($fields as $field) {
            if ($field !== '*' && !preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                throw new \InvalidArgumentException('Invalid authoritative read field');
            }
            $select[] = $field === '*' ? '*' : '`' . $field . '`';
        }
        if (!$select) throw new \InvalidArgumentException('Missing authoritative read fields');
        $shard = $this->db->resolveShard($this->getTable(), $condition);
        $read = function () use ($table, $where, $select, $condition, $shard): array {
            $statement = $this->db->connect('master', $shard)->prepare(
                'SELECT ' . implode(',', $select) . ' FROM `' . $table . '` WHERE ' . implode(' AND ', $where)
            );
            try {
                // 与 PdoDriver 的二进制绑定规则一致，不能把 UUID/hash 参数改成文本合同。
                foreach (array_values($condition) as $index => $value) {
                    $binary = is_string($value) && (strpos($value, "\0") !== false
                        || (strlen($value) < 1024 && extension_loaded('mbstring') && !mb_check_encoding($value, 'UTF-8')));
                    $statement->bindValue($index + 1, $value, $binary ? \PDO::PARAM_LOB : \PDO::PARAM_STR);
                }
                $statement->execute();
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
                return is_array($row) ? $row : [];
            } finally {
                $statement->closeCursor();
            }
        };
        return $this->db->inTransaction($shard) ? $read() : $this->db->transaction($read, 1, $shard);
    }

    /** 小批次整数定位的主库读取；用于静态发布复核，不改变普通 find 的读路由。 */
    public function readManyFromMaster(array $locators, array $fields): array
    {
        if (!$locators) return [];
        if (count($locators) > 100 || !$fields) throw new \InvalidArgumentException('Invalid master locator batch');
        $locators = array_values($locators);
        $table = $this->db->prefix . $this->getTable();
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new \InvalidArgumentException('Invalid master read table');
        $select = [];
        foreach ($fields as $field) {
            if ($field === '*' && $fields === ['*']) { $select[] = '*'; continue; }
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field)) throw new \InvalidArgumentException('Invalid master batch field');
            $select[] = '`' . $field . '`';
        }
        $groups = [];
        $columns = array_keys($locators[0]);
        foreach ($locators as $locator) {
            if (array_keys($locator) !== $columns || !$columns) throw new \InvalidArgumentException('Inconsistent master batch locator');
            foreach ($locator as $column => $value) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', $column) || !is_int($value) || $value <= 0) throw new \InvalidArgumentException('Master batch requires positive integer locators');
            }
            $groups[$this->db->resolveShard($this->getTable(), $locator)][] = $locator;
        }
        $result = [];
        foreach ($groups as $shard => $rows) {
            $read = function () use ($shard, $rows, $columns, $table, $select): array {
                $where = [];
                foreach ($columns as $column) $where[] = '`' . $column . '` = ?';
                $tuple = '(' . implode(' AND ', $where) . ')';
                $parameters = [];
                foreach ($rows as $row) $parameters = array_merge($parameters, array_values($row));
                $statement = $this->db->connect('master', (string)$shard)->prepare('SELECT ' . implode(',', $select) . ' FROM `' . $table . '` WHERE ' . implode(' OR ', array_fill(0, count($rows), $tuple)));
                try { $statement->execute($parameters); return $statement->fetchAll(\PDO::FETCH_ASSOC); }
                finally { $statement->closeCursor(); }
            };
            $result = array_merge($result, $this->db->inTransaction((string)$shard) ? $read() : $this->db->transaction($read, 1, (string)$shard));
        }
        return $result;
    }

    /**
     * 分页查询多条记录
     *
     * @param array $condition
     * @param array $orderBy
     * @param int $page
     * @param int $pageSize
     * @param string $key 结果数组键名
     * @param array $fields
     * @return array
     */
    public function find(array $condition = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array
    {
        return $this->db->query($this->getTable(), $condition, $orderBy, $page, $pageSize, $key, $fields);
    }

    /**
     * 删除记录
     *
     * @param array $condition
     * @return int
     */
    public function delete(array $condition = []): int
    {
        return $this->db->delete($this->getTable(), $condition);
    }

    /**
     * 统计记录数
     *
     * @param array $condition
     * @return int
     */
    public function count(array $condition = []): int
    {
        return $this->db->count($this->getTable(), $condition);
    }

    /**
     * 获取某字段最大值
     *
     * @param string $field
     * @return int
     */
    public function maxid(string $field = 'id'): int
    {
        return $this->db->maxid($this->getTable(), $field);
    }

    /**
     * 批量插入
     *
     * @param array $data
     * @return mixed
     */
    public function bulkInsert(array $data = []): int
    {
        if (empty($data)) {
            return 0;
        }
        return $this->db->bulkInsert($this->getTable(), $data);
    }

    /**
     * 批量更新
     *
     * @param array $update
     * @param string $keyColumn
     * @param array $wheres
     * @return int
     */
    public function bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int
    {
        if (empty($update)) {
            return 0;
        }
        return $this->db->bulkUpdate($this->getTable(), $update, $keyColumn, $wheres);
    }
}
