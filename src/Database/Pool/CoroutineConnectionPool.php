<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Database\Pool;

use Framework\Logger\LoggerInterface;

// 协程环境
class CoroutineConnectionPool extends AbstractPool
{
    /**
     * @var object[] 按 shard 分组的连接通道 (\Swoole\Coroutine\Channel)，实现 O(1) 检索
     */
    protected $poolChannels = [];

    /** @var \WeakMap|\SplObjectStorage 存储 PDO → Node 映射 */
    protected $connectionToNode;

    /** @var \WeakMap|\SplObjectStorage 标记中毒连接 */
    protected $taintedConnections;

    /** @var \WeakMap|\SplObjectStorage 标记当前 query 以异常结束 */
    protected $failedQueries;

    /** @var array ["role:shard" => int] 协程安全总连接计数器 */
    protected $channelTotals = [];

    /** @var int 健康检查定时器 ID */
    protected $healthCheckTimerId = 0;

    /** @var int 健康检查间隔（毫秒） */
    private $healthCheckInterval;

    /** @var array 定时器引用静态映射表 [spl_object_id => pool] */
    private static $timerRefs = [];

    /** @var \Swoole\Lock|null */
    protected $creationLock;

    /** @var \Swoole\Atomic|null */
    protected $establishingAtomic;

    /** @var int[] [role:shard => 正在建立的连接数] */
    protected $establishingByChannel = [];

    public function __construct(
        \Framework\Database\Interfaces\DatabaseInterface $driver,
        array $masterConfig,
        array $slavesConfig,
        ?\Framework\Database\Pool\LoadBalancer\LoadBalancerInterface $loadBalancer = null,
        int $minConnections = 2,
        int $maxConnections = 8,
        int $timeout = 5,
        int $threshold = 20,
        float $adjustThreshold = 0.75,
        ?callable $connectionValidationCallback = null,
        ?LoggerInterface $logger = null,
        int $healthCheckInterval = 60000
    ) {
        parent::__construct(
            $driver,
            $masterConfig,
            $slavesConfig,
            $loadBalancer,
            $minConnections,
            $maxConnections,
            $timeout,
            $threshold,
            $adjustThreshold,
            $connectionValidationCallback,
            $logger
        );

        $this->healthCheckInterval = $healthCheckInterval;

        $class = class_exists('WeakMap') ? 'WeakMap' : 'SplObjectStorage';
        $this->connectionToNode = new $class();
        $this->taintedConnections = new $class();
        $this->failedQueries = new $class();

        if (\extension_loaded('swoole')) {
            $this->creationLock = new \Swoole\Lock(SWOOLE_MUTEX);
            $this->establishingAtomic = new \Swoole\Atomic(0);
        }
    }

    /**
     * 启动健康检查定时器（惰性初始化，避免在 Swoole Server 启动前创建事件循环）
     */
    private function startHealthCheckTimer(): void
    {
        if (!\extension_loaded('swoole') || PHP_SAPI !== 'cli') {
            return;
        }
        if ($this->healthCheckTimerId > 0) {
            return;
        }
        $timerClass = "\\Swoole\\Timer";
        if (!class_exists($timerClass)) {
            return;
        }
        $oid = spl_object_id($this);
        self::$timerRefs[$oid] = $this;
        $this->healthCheckTimerId = call_user_func([$timerClass, "tick"], $this->healthCheckInterval, function () use ($oid) {
            $runner = function () use ($oid) {
                $pool = self::$timerRefs[$oid] ?? null;
                if ($pool === null) {
                    return;
                }
                try {
                    $pool->checkAndRecoverConnections();
                } catch (\Throwable $e) {
                    // 捕获异常避免定时器中断
                }
            };
            if (\function_exists('go')) {
                go($runner);
            } else {
                $runner();
            }
        });
    }

    public function __destruct() {
        $this->closeAll();
    }

    /**
     * 协程池禁用 FPM 的数组扩缩容逻辑
     */
    public function adjustPoolSize(string $shard = ''): void
    {
        // Channel 自身管理容量，无需外部扩缩容
    }

    /**
     * @inheritDoc
     */
    public function preWarm(int $num, string $shard = ''): void
    {
        $pool = $this->getShardPool($shard);
        $target = min(max(0, $num), $this->maxConnections);
        if ($target === 0 || empty($pool->nodes)) {
            return;
        }

        $nodes = array_values(array_filter($pool->nodes, function (Node $node) {
            return $node->fusedUntil <= time();
        }));
        while ($this->getChannelTotal('slave', $shard) < $target && !empty($nodes)) {
            $node = $this->selectPreWarmNode($nodes);
            if (!$node) {
                break;
            }
            $this->createPooledConnection($node, $shard, 'slave');
        }

        while ($this->getChannelTotal('master', $shard) < $target) {
            $this->createPooledConnection(null, $shard, 'master');
        }
    }

    /**
     * @inheritDoc
     */
    public function getConnection(string $role = 'slave', string $shard = ''): \PDO
    {
        $this->startHealthCheckTimer();

        $actualRole = ($role === 'master' || $this->inTransaction($shard)) ? 'master' : 'slave';

        if ($actualRole === 'master') {
            $ctx = self::coroContext();
            if ($ctx !== null) {
                $masters = $ctx['dbMasters'] ?? [];
                if (isset($masters[$shard])) {
                    return $masters[$shard];
                }
            }
        }

        // 若从库全熔断，降级到主库（禁止主库进入从库 Channel）
        if ($actualRole === 'slave') {
            $shardPool = $this->getShardPool($shard);
            $availableNodes = array_values(array_filter($shardPool->nodes, function (Node $n) {
                return $n->fusedUntil <= time();
            }));
            if (empty($availableNodes) && !empty($shardPool->nodes)) {
                return $this->getMaster($shard);
            }
        }

        $channel = $this->getPoolChannel($actualRole, $shard);

        if (!$channel->isEmpty()) {
            $pdo = $channel->pop(0.05);
            if ($pdo instanceof \PDO) {
                while (!$this->validateConnection($pdo)) {
                    $this->detachConnection($pdo);
                    $this->decrChannelTotal($actualRole, $shard);
                    $pdo = $channel->pop(0.05);
                    if (!$pdo instanceof \PDO) {
                        break;
                    }
                }
                if ($pdo instanceof \PDO) {
                    $this->markConnectionActive($pdo);
                    if ($actualRole === 'master') {
                        $this->bindMasterToContext($pdo, $shard);
                    }
                    $ctx = self::coroContext();
                    if ($ctx !== null) {
                        $actives = $ctx['dbActiveConnections'] ?? [];
                        if (empty($actives) && \is_callable(['\Swoole\Coroutine', 'defer']) && empty($ctx['dbDeferRegistered'])) {
                            $ctx['dbDeferRegistered'] = true;
                            \Swoole\Coroutine::defer(function () {
                                $context = self::coroContext();
                                if ($context !== null) {
                                    $remaining = $context['dbActiveConnections'] ?? [];
                                    foreach ($remaining as $unreleasedPdo) {
                                        $this->taintedConnections[$unreleasedPdo] = true;
                                        $this->releaseConnection($unreleasedPdo);
                                    }
                                    $context['dbActiveConnections'] = [];
                                    unset($context['dbDeferRegistered']);
                                    $this->evictCurrentConnection();
                                }
                            });
                        }
                        $actives[\spl_object_id($pdo)] = $pdo;
                        $ctx['dbActiveConnections'] = $actives;
                    }
                    return $pdo;
                }
                // 若 channel 中无有效连接，继续走下方新建逻辑
            }
        }

        // 原子控制连接数上限，防止 Race Condition
        $createdHere = false;
        $locked = false;
        $establishingKey = "{$actualRole}:{$shard}";
        if ($this->creationLock) {
            $this->creationLock->lock();
            $locked = true;
        }
        try {
            $establishing = $this->establishingByChannel[$establishingKey] ?? 0;
            $currentTotal = $this->getChannelTotal($actualRole, $shard) + $establishing;
            if ($currentTotal < $this->maxConnections) {
                $this->establishingByChannel[$establishingKey] = $establishing + 1;
                $createdHere = true;
                if ($locked) {
                    $this->creationLock->unlock();
                    $locked = false;
                }
                try {
                    $this->createSlave($shard, $actualRole);
                } catch (\Throwable $e) {
                    $this->decrEstablishing($establishingKey);
                    throw $e;
                }
                $pdo = $channel->pop($this->timeout);
                $this->decrEstablishing($establishingKey);
                if (!$pdo instanceof \PDO) {
                    $this->failed++;
                    throw \Framework\Exception\Infra\PoolException::poolError("Coroutine wait timeout for DB: role={$actualRole} shard={$shard}");
                }
                $this->markConnectionActive($pdo);
                if ($actualRole === 'master') {
                    $this->bindMasterToContext($pdo, $shard);
                }
                $ctx = self::coroContext();
                if ($ctx !== null) {
                    $actives = $ctx['dbActiveConnections'] ?? [];
                    if (empty($actives) && \is_callable(['\Swoole\Coroutine', 'defer']) && empty($ctx['dbDeferRegistered'])) {
                        $ctx['dbDeferRegistered'] = true;
                        \Swoole\Coroutine::defer(function () {
                            $context = self::coroContext();
                            if ($context !== null) {
                                $remaining = $context['dbActiveConnections'] ?? [];
                                foreach ($remaining as $unreleasedPdo) {
                                    $this->taintedConnections[$unreleasedPdo] = true;
                                    $this->releaseConnection($unreleasedPdo);
                                }
                                $context['dbActiveConnections'] = [];
                                unset($context['dbDeferRegistered']);
                                $this->evictCurrentConnection();
                            }
                        });
                    }
                    $actives[\spl_object_id($pdo)] = $pdo;
                    $ctx['dbActiveConnections'] = $actives;
                }
                return $pdo;
            }
        } finally {
            if ($locked) {
                $this->creationLock->unlock();
            }
        }

        $pdo = $channel->pop($this->timeout);
        if (!$pdo instanceof \PDO) {
            $this->failed++;
            throw \Framework\Exception\Infra\PoolException::poolError("Coroutine wait timeout for DB: role={$actualRole} shard={$shard}");
        }

        $this->markConnectionActive($pdo);
        if ($actualRole === 'master') {
            $this->bindMasterToContext($pdo, $shard);
        }

        $ctx = self::coroContext();
        if ($ctx !== null) {
            $actives = $ctx['dbActiveConnections'] ?? [];
            if (empty($actives) && \is_callable(['\Swoole\Coroutine', 'defer']) && empty($ctx['dbDeferRegistered'])) {
                $ctx['dbDeferRegistered'] = true;
                \Swoole\Coroutine::defer(function () {
                    $context = self::coroContext();
                    if ($context !== null) {
                        $remaining = $context['dbActiveConnections'] ?? [];
                        foreach ($remaining as $unreleasedPdo) {
                            $this->taintedConnections[$unreleasedPdo] = true;
                            $this->releaseConnection($unreleasedPdo);
                        }
                        $context['dbActiveConnections'] = [];
                        unset($context['dbDeferRegistered']);
                        $this->evictCurrentConnection();
                    }
                });
            }
            $actives[\spl_object_id($pdo)] = $pdo;
            $ctx['dbActiveConnections'] = $actives;
        }

        return $pdo;
    }

    /**
     * @inheritDoc
     */
    protected function getMaster(string $shard = ''): \PDO
    {
        return $this->getConnection('master', $shard);
    }

    /**
     * 将主库连接绑定到当前协程上下文
     */
    protected function bindMasterToContext(\PDO $pdo, string $shard): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $masters = $ctx['dbMasters'] ?? [];
            $masters[$shard] = $pdo;
            $ctx['dbMasters'] = $masters;
        }
    }

    /**
     * 获取连接通道
     *
     * @param string $role
     * @param string $shard
     * @return object 实际返回 \Swoole\Coroutine\Channel
     */
    protected function getPoolChannel(string $role, string $shard): object
    {
        $key = "{$role}:{$shard}";
        if (!isset($this->poolChannels[$key])) {
            $class = "\\Swoole\\Coroutine\\Channel";
            $this->poolChannels[$key] = new $class($this->maxConnections);
        }
        return $this->poolChannels[$key];
    }

    /**
     * 重载创建逻辑
     */
    /**
     * @param Node[] $nodes
     */
    private function selectPreWarmNode(array $nodes)
    {
        $selected = null;
        $selectedRatio = null;
        foreach ($nodes as $node) {
            $ratio = $node->totalConnections / max(1, $node->weight);
            if ($selected === null || $ratio < $selectedRatio) {
                $selected = $node;
                $selectedRatio = $ratio;
            }
        }
        return $selected;
    }

    private function createPooledConnection(?Node $targetNode, string $shard, string $role): \PDO
    {
        if ($targetNode) {
            $pdo = $this->factory->createConnectionFromConfig($targetNode->config);
        } else {
            $pdo = $this->driver->createFreshConnection('master', $shard);
        }

        $channel = $this->getPoolChannel($role, $shard);
        if (!$channel->push($pdo, $this->timeout)) {
            $pdo = null;
            throw \Framework\Exception\Infra\PoolException::poolError("Failed to push new connection into pool: role={$role} shard={$shard}");
        }

        if ($targetNode) {
            $targetNode->totalConnections++;
            $this->connectionToNode[$pdo] = $targetNode;
        }
        $this->incrChannelTotal($role, $shard);
        return $pdo;
    }

    private function markConnectionActive(\PDO $pdo): void
    {
        $node = isset($this->connectionToNode[$pdo]) ? $this->connectionToNode[$pdo] : null;
        if ($node) {
            $node->activeConnections++;
        }
    }

    protected function createSlave(string $shard = '', string $role = 'slave'): \PDO
    {
        $shardPool = $this->getShardPool($shard);
        $nodes = array_values(array_filter($shardPool->nodes, function (Node $n) {
            return $n->fusedUntil <= time();
        }));

        $targetNode = null;
        if ($role === 'slave' && $this->loadBalancer instanceof LoadBalancer\NodeLoadBalancerInterface) {
            $targetNode = $this->loadBalancer->selectNode($nodes);
        }
        if (!$targetNode) {
            $targetNode = $role === 'master' ? null : ($nodes[0] ?? null);
        }

        return $this->createPooledConnection($targetNode, $shard, $role);
    }

    protected function incrChannelTotal(string $role, string $shard): void
    {
        $key = "{$role}:{$shard}";
        $this->channelTotals[$key] = ($this->channelTotals[$key] ?? 0) + 1;
    }

    protected function decrChannelTotal(string $role, string $shard): void
    {
        $key = "{$role}:{$shard}";
        $this->channelTotals[$key] = max(0, ($this->channelTotals[$key] ?? 0) - 1);
    }

    protected function getChannelTotal(string $role, string $shard): int
    {
        return $this->channelTotals["{$role}:{$shard}"] ?? 0;
    }

    private function decrEstablishing(string $key): void
    {
        $remaining = max(0, ($this->establishingByChannel[$key] ?? 0) - 1);
        if ($remaining === 0) {
            unset($this->establishingByChannel[$key]);
            return;
        }
        $this->establishingByChannel[$key] = $remaining;
    }

    protected function detachConnection(\PDO $pdo): void
    {
        $node = isset($this->connectionToNode[$pdo]) ? $this->connectionToNode[$pdo] : null;
        if ($node) {
            $node->activeConnections = max(0, $node->activeConnections - 1);
            $node->totalConnections = max(0, $node->totalConnections - 1);
            unset($this->connectionToNode[$pdo]);
        }
    }

    /**
     * @inheritDoc
     */
    public function releaseConnection(\PDO $connection): void
    {
        $ctx = self::coroContext();
        $oid = \spl_object_id($connection);
        $wasActive = false;
        $ownedMasterShard = null;
        if ($ctx !== null) {
            $actives = $ctx['dbActiveConnections'] ?? [];
            if (isset($actives[$oid])) {
                $wasActive = true;
                unset($actives[$oid]);
                $ctx['dbActiveConnections'] = $actives;
            }
            $masters = $ctx['dbMasters'] ?? [];
            foreach ($masters as $masterShard => $masterPdo) {
                if ($masterPdo === $connection) {
                    $ownedMasterShard = $masterShard;
                    break;
                }
            }
        }

        if (isset($this->taintedConnections[$connection])) {
            $node = isset($this->connectionToNode[$connection]) ? $this->connectionToNode[$connection] : null;
            $shard = $node ? $node->shard : '';
            $role = $node ? 'slave' : 'master';
            if ($role === 'master' && $ctx !== null) {
                $masters = $ctx['dbMasters'] ?? [];
                foreach ($masters as $s => $pdo) {
                    if ($pdo === $connection) {
                        $shard = $s;
                        unset($masters[$s]);
                        break;
                    }
                }
                $ctx['dbMasters'] = $masters;

                $levels = $ctx['dbTransactionLevels'] ?? [];
                unset($levels[$shard]);
                $ctx['dbTransactionLevels'] = $levels;

                $flags = $ctx['dbShouldRollback'] ?? [];
                unset($flags[$shard]);
                $ctx['dbShouldRollback'] = $flags;
            }
            $this->detachConnection($connection);
            unset($this->taintedConnections[$connection]);
            if (isset($this->failedQueries[$connection])) {
                unset($this->failedQueries[$connection]);
            }
            $this->decrChannelTotal($role, $shard);
            return;
        }

        $node = isset($this->connectionToNode[$connection]) ? $this->connectionToNode[$connection] : null;

        if ($node) {
            if ($ctx !== null && !$wasActive) {
                return;
            }
            $node->activeConnections = max(0, $node->activeConnections - 1);
            $shard = $node->shard;
            $role = 'slave';
        } else {
            $shard = $ownedMasterShard !== null ? $ownedMasterShard : '';
            $role = 'master';
        }

        if ($role === 'master' && $this->inTransaction($shard)) {
            return;
        }
        if ($role === 'master' && $ctx !== null && !$wasActive && $ownedMasterShard === null) {
            return;
        }

        if ($connection->inTransaction()) {
            try { $connection->rollBack(); } catch (\Throwable $e) {
                $this->logError("DB rollback failed on release", ['exception' => $e]);
            }
        }

        if ($role === 'master') {
            $ctx = self::coroContext();
            if ($ctx !== null) {
                $masters = $ctx['dbMasters'] ?? [];
                foreach ($masters as $s => $pdo) {
                    if ($pdo === $connection) {
                        unset($masters[$s]);
                        $ctx['dbMasters'] = $masters;
                        break;
                    }
                }
            }
        }

        $this->getPoolChannel($role, $shard)->push($connection, $this->timeout);
    }

    public function reportError(\PDO $connection, \Throwable $e): void
    {
        $this->failedQueries[$connection] = true;
        if (!$this->isConnectionFailure($e)) {
            return;
        }
        $node = isset($this->connectionToNode[$connection]) ? $this->connectionToNode[$connection] : null;
        if ($node) {
            $node->errorCount++;
            if ($node->errorCount >= 5) {
                $node->fusedUntil = time() + 30;
                $node->errorCount = 0;
                $this->logError("DB node '{$node->nodeId}' fused.", ['node_id' => $node->nodeId]);
            }
        }
        $this->taintedConnections[$connection] = true;
    }

    public function trackQueryStart(\PDO $connection): void
    {
        $node = isset($this->connectionToNode[$connection]) ? $this->connectionToNode[$connection] : null;
        if ($node) {
            $node->activeQueries = ($node->activeQueries ?? 0) + 1;
        }
    }

    public function trackQueryEnd(\PDO $connection): void
    {
        $failed = isset($this->failedQueries[$connection]);
        if ($failed) {
            unset($this->failedQueries[$connection]);
        }
        $node = isset($this->connectionToNode[$connection]) ? $this->connectionToNode[$connection] : null;
        if ($node) {
            $node->activeQueries = max(0, ($node->activeQueries ?? 0) - 1);
            if (!$failed && $node->fusedUntil <= time()) {
                $node->errorCount = 0;
            }
        }
    }

    public function checkAndRecoverConnections(string $shard = ''): void
    {
        $pools = $shard !== '' ? [$shard => $this->getShardPool($shard)] : $this->shardPools;
        foreach ($pools as $shard => $pool) {
            foreach ($pool->nodes as $node) {
                $testPdo = null;
                try {
                    $testPdo = $this->factory->createConnectionFromConfig($node->config);
                    if ($this->validateConnection($testPdo)) {
                        if ($node->fusedUntil > time()) {
                            $node->fusedUntil = 0;
                            $node->errorCount = 0;
                        }
                    } else {
                        $node->errorCount++;
                        if ($node->errorCount >= 5) {
                            $node->fusedUntil = time() + 30;
                            $node->errorCount = 0;
                        }
                    }
                } catch (\Throwable $e) {
                    $node->errorCount++;
                    if ($node->errorCount >= 5) {
                        $node->fusedUntil = time() + 30;
                        $node->errorCount = 0;
                    }
                } finally {
                    $testPdo = null;
                }
            }

            // 补充 master channel 健康抽检
            $masterChannel = $this->getPoolChannel('master', $shard);
            if (!$masterChannel->isEmpty()) {
                $pdo = $masterChannel->pop(0.001);
                if ($pdo instanceof \PDO) {
                    if (!$this->validateConnection($pdo)) {
                        $this->decrChannelTotal('master', $shard);
                    } else {
                        $masterChannel->push($pdo, $this->timeout);
                    }
                }
            }
        }
    }

    public function checkHealth(string $shard = ''): array
    {
        return parent::checkHealth($shard);
    }

    /**
     * @inheritDoc
     */
    public function closeAll(): void
    {
        if ($this->healthCheckTimerId > 0) {
            $timerClass = "\\Swoole\\Timer";
            if (class_exists($timerClass)) {
                call_user_func([$timerClass, 'clear'], $this->healthCheckTimerId);
            }
            $this->healthCheckTimerId = 0;
        }
        $oid = spl_object_id($this);
        unset(self::$timerRefs[$oid]);
        foreach ($this->poolChannels as $channel) {
            // Worker 停机与析构可发生在非协程上下文；Channel::pop() 此时会 fatal。
            // close() 可在该生命周期安全调用，并在释放 Channel 引用时一并释放空闲 PDO。
            $channel->close();
        }
        $this->poolChannels = [];
        $class = class_exists('WeakMap') ? 'WeakMap' : 'SplObjectStorage';
        $this->connectionToNode = new $class();
        $this->taintedConnections = new $class();
        $this->failedQueries = new $class();
        $this->channelTotals = [];
        $this->establishingByChannel = [];

        foreach ($this->shardPools as $shard => $pool) {
            $pool->master = null;
            $pool->connections = [];
            $pool->connectionRegistry = [];
            foreach ($pool->nodes as $node) {
                $node->activeConnections = 0;
                $node->totalConnections = 0;
                $node->activeQueries = 0;
                $node->errorCount = 0;
                $node->fusedUntil = 0;
            }
        }
    }

    /**
     * 仅驱逐当前协程上下文中的 master 连接
     */
    public function evictCurrentConnection(string $shard = ''): void
    {
        $ctx = self::coroContext();
        if ($ctx !== null) {
            $masters = $ctx['dbMasters'] ?? [];
            if ($shard !== '') {
                if (isset($masters[$shard])) {
                    $pdo = $masters[$shard];
                    $this->detachConnection($pdo);

                    $levels = $ctx['dbTransactionLevels'] ?? [];
                    if (isset($levels[$shard])) {
                        unset($levels[$shard]);
                        $ctx['dbTransactionLevels'] = $levels;
                    }

                    $this->taintedConnections[$pdo] = true;
                    $this->releaseConnection($pdo);
                    unset($masters[$shard]);
                    $ctx['dbMasters'] = $masters;
                }
            } else {
                $ctx['dbTransactionLevels'] = [];
                foreach ($masters as $s => $pdo) {
                    $this->detachConnection($pdo);
                    $this->taintedConnections[$pdo] = true;
                    $this->releaseConnection($pdo);
                }
                $ctx['dbMasters'] = [];
            }
        }
    }

    protected function getConnectionCount(string $shard): int
    {
        return $this->getChannelTotal('slave', $shard);
    }

    protected function calcUtilization(string $shard = ''): float
    {
        $total = $this->getChannelTotal('slave', $shard);
        if ($total === 0) {
            return 0.0;
        }
        $busy = 0;
        $pool = $this->getShardPool($shard);
        foreach ($pool->nodes as $node) {
            $busy += $node->activeConnections;
        }
        return $busy / $total;
    }

    public function stats(): array
    {
        $out = [];
        foreach ($this->shardPools as $shard => $pool) {
            $total = $this->getChannelTotal('slave', $shard);
            $busy = 0;
            foreach ($pool->nodes as $node) {
                $busy += $node->activeConnections;
            }
            $out[$shard] = [
                'total_requests' => $this->totalRequests,
                'successful' => $this->successful,
                'failed' => $this->failed,
                'connections_total' => $total,
                'connections_active' => $busy,
                'connections_idle' => max(0, $total - $busy),
                'utilization' => $total > 0 ? $busy / $total : 0.0,
            ];
        }
        return $out;
    }
}
