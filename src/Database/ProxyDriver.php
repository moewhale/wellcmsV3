<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database;

use PDO;
use PDOStatement;
use Throwable;
use Framework\Database\Driver\PdoDriver;
use Framework\Database\Pool\PoolInterface;
use Framework\Exception\Infra\RollbackOnlyException;

/**
 * ProxyDriver
 *
 * 继承自 PdoDriver，将底层的 connect() 重写为从连接池获取连接。
 * 上层代码依然调用 insert()/query()/transaction() 等方法，
 * 但所有的 PDO 实例都来自注入的 PoolInterface。
 */
class ProxyDriver extends PdoDriver
{
    /**
     * @var PoolInterface
     */
    public $pool;

    /** @var array FPM 环境下 PDO 对象级别的隔离级别映射 [spl_object_id => level] */
    protected $isolationLevels = [];

    /**
     * 构造时注入配置和连接池
     *
     * @param array          $appConfig 与 PdoDriver 相同的数据库配置
     * @param PoolInterface  $pool   实现了主从分离、读写分离的连接池
     */
    public function __construct(array $appConfig, PoolInterface $pool)
    {
        parent::__construct($appConfig);
        $this->pool = $pool;
    }

    /**
     * 重写父类 connect，所有 CRUD 调用所需的 PDO
     * 都由连接池提供，而非重新 new PDO(...)
     *
     * @param string $role  'master' 或 'slave'
     * @param string $shard 可选的分表标识
     * @return PDO
     */
    public function connect(string $role = 'master', string $shard = ''): PDO
    {
        // 如果当前处在一个其他分片的事务中，严禁切换到当前分片（跨分片隔离机制）
        if ($this->pool instanceof \Framework\Database\Pool\AbstractPool) {
            $this->pool->checkCrossShardTransaction($shard);
        }

        $pdo = $this->pool->getConnection($role, $shard);

        // 惰性校准隔离级别，防止连接池中的状态污染
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $targetLevel = $ctx['dbIsolationLevel'] ?? $this->defaultIsolationLevel;

            // 将隔离级别映射存入协程上下文，避免进程级 static 共享
            $isolationLevels = $ctx['dbIsolationLevels'] ?? [];
            $oid = \spl_object_id($pdo);
            $currentLevel = $isolationLevels[$oid] ?? null;

            if ($currentLevel !== $targetLevel) {
                // 如果在事务中且是 PostgreSQL，跳过校准以防 25P02 覆盖原始错误
                // 事务启动时 beginTransaction 已经处理过隔离级别了
                if (!$this->pool->inTransaction($shard)) {
                    try {
                        $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
                        $isolationLevels[$oid] = $targetLevel;
                        $ctx['dbIsolationLevels'] = $isolationLevels;
                    } catch (\Throwable $e) {
                        try {
                            $this->pool->releaseConnection($pdo);
                        } catch (\Throwable $releaseEx) {
                            // ignore
                        }
                        throw $e;
                    }
                }
            }
        } elseif ($this->pool instanceof \Framework\Database\Pool\FpmConnectionPool) {
            if (!$this->pool->inTransaction($shard)) {
                try {
                    $this->pool->configureIsolationLevel($pdo, $shard, $this->defaultIsolationLevel,
                        $this->grammar->compileIsolationLevel($this->defaultIsolationLevel));
                } catch (\Throwable $e) {
                    $this->pool->reportError($pdo, $e);
                    $this->pool->releaseConnection($pdo);
                    throw $e;
                }
            }
        } else {
            // FPM 环境下使用对象级映射进行隔离级别校准
            $targetLevel = $this->defaultIsolationLevel;
            $oid = \spl_object_id($pdo);
            $currentLevel = $this->isolationLevels[$oid] ?? null;

            if ($currentLevel !== $targetLevel) {
                if (!$this->pool->inTransaction($shard)) {
                    try {
                        $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
                        $this->isolationLevels[$oid] = $targetLevel;
                    } catch (\Throwable $e) {
                        try {
                            $this->pool->releaseConnection($pdo);
                        } catch (\Throwable $releaseEx) {
                            // ignore
                        }
                        unset($this->isolationLevels[$oid]);
                        throw $e;
                    }
                }
            }
        }

        return $pdo;
    }

    /**
     * 重写父类 release，将连接还给池子
     */
    protected function release(PDO $pdo): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $oid = \spl_object_id($pdo);
            $isolationLevels = $ctx['dbIsolationLevels'] ?? [];
            unset($isolationLevels[$oid]);
            $ctx['dbIsolationLevels'] = $isolationLevels;
        } else {
            unset($this->isolationLevels[\spl_object_id($pdo)]);
        }
        $this->pool->releaseConnection($pdo);
    }

    public function beginTransaction(string $shard = ''): bool
    {
        $ctx = self::coroContext();
        $fpmPool = $ctx === null && $this->pool instanceof \Framework\Database\Pool\FpmConnectionPool;
        // 在 BEGIN 之前校准，确保当前事务即使用目标隔离级别。
        if ($fpmPool && !$this->pool->inTransaction($shard)) $this->connect('master', $shard);
        $result = $this->pool->beginTransaction($shard);
        $this->setTransactionState(true);

        if ($result && $this->pool->getTransactionLevel($shard) === 1) {
            $this->resetTransactionCallbacks();
        }

        // 仅在事务层级从 0 -> 1 时设置隔离级别，避免嵌套事务重复执行 SET SESSION
        if ($ctx !== null && $this->pool->getTransactionLevel($shard) === 1) {
            $targetLevel = $ctx['dbIsolationLevel'] ?? $this->defaultIsolationLevel;
            $pdo = $this->pool->getConnection('master', $shard);
            $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
            $isolationLevels = $ctx['dbIsolationLevels'] ?? [];
            $isolationLevels[\spl_object_id($pdo)] = $targetLevel;
            $ctx['dbIsolationLevels'] = $isolationLevels;
        } elseif ($ctx === null && !$fpmPool && $this->pool->getTransactionLevel($shard) === 1) {
            $targetLevel = $this->defaultIsolationLevel;
            $pdo = $this->pool->getConnection('master', $shard);
            $pdo->exec($this->grammar->compileIsolationLevel($targetLevel));
            $this->isolationLevels[\spl_object_id($pdo)] = $targetLevel;
        }

        return $result;
    }

    public function commit(string $shard = ''): bool
    {
        try {
            $result = $this->pool->commit($shard);
            $active = $this->pool->inTransaction($shard);
            $this->setTransactionState($active);
            if ($result && !$active) {
                $this->finalizeTransactionCallbacks(true);
            }
            return $result;
        } catch (\Throwable $e) {
            $active = $this->pool->inTransaction($shard);
            $this->setTransactionState($active);
            if (!$active) {
                if ($e instanceof RollbackOnlyException) {
                    // 内层 rollback-only 在最外层 commit 时已经实际执行回滚。
                    $this->finalizeTransactionCallbacks(false);
                } else {
                    // commit 异常不能证明服务端已回滚，禁止误执行补偿回调。
                    $this->clearTransactionCallbacks();
                }
            }
            throw $e;
        }
    }

    public function rollback(string $shard = ''): bool
    {
        try {
            $result = $this->pool->rollback($shard);
            $active = $this->pool->inTransaction($shard);
            $this->setTransactionState($active);
            if ($result && !$active) {
                $this->finalizeTransactionCallbacks(false);
            }
            return $result;
        } catch (\Throwable $e) {
            $active = $this->pool->inTransaction($shard);
            $this->setTransactionState($active);
            if (!$active) $this->clearTransactionCallbacks();
            throw $e;
        }
    }

    public function inTransaction(string $shard = ''): bool
    {
        return $this->pool->inTransaction($shard);
    }

    /**
     * 显式设置成功后同步目标隔离级别，供其它连接与替换连接校准
     */
    public function setIsolationLevel(string $level, string $shard = ''): void
    {
        $level = strtoupper($level);
        parent::setIsolationLevel($level, $shard);
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $ctx['dbIsolationLevel'] = $level;
        }
        $this->defaultIsolationLevel = $level;
    }

    protected function applyIsolationLevel(PDO $pdo, string $level, string $shard): void
    {
        parent::applyIsolationLevel($pdo, $level, $shard);
        if (self::coroContext() === null && $this->pool instanceof \Framework\Database\Pool\FpmConnectionPool) {
            $this->pool->recordIsolationLevel($pdo, $shard, $level);
        }
    }

    /**
     * 重写执行逻辑，挂载性能追踪与故障上报钩子
     */
    protected function logAndExec(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $this->pool->trackQueryStart($pdo);
        try {
            return parent::logAndExec($pdo, $sql, $params);
        } catch (Throwable $e) {
            $this->pool->reportError($pdo, $e);
            throw $e;
        } finally {
            $this->pool->trackQueryEnd($pdo);
        }
    }
}
