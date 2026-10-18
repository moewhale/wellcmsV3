<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Jobs;

use Framework\Logger\LoggerInterface;
use Framework\Scheduler\TaskManage;

/**
 * 编译缓存失效巡检 + 静态生成物回收定时任务。
 *
 * 职责一：显式失效巡检（本任务为唯一自动触发点）
 *   生产请求路径不再计算插件树内容指纹（历史上每次请求要读取并哈希 165 个文件，
 *   且会被 rsync 同步中途的瞬时态或 *.tmp.* 残留误触发，导致请求内清空全部编译产物）。
 *   改为本任务在后台比对内容指纹：发生漂移时调用
 *   Compile::publishInvalidationIfStale() —— 先清空固定编译缓存，再发布新的
 *   artifact_epoch 版本标记，随后重建 manifest；container.php 由下一次 Web 启动生成。
 *   下一次请求读到新标记即换代码际；请求路径本身绝不清空编译缓存。
 *   需要立即生效（不等本任务周期）时，使用后台「清除缓存」按钮，
 *   它走同一条 Compile::flushCache() 路径。
 *
 * 职责二：静态指纹垃圾回收
 *   public/static/runtime/ 下的聚合产物按内容指纹命名（{name}.{8位hash}.{type}），
 *   内容变化 → 新文件名 → 旧指纹文件成为孤儿。但旧文件可能被浏览器缓存、
 *   CDN 或仍在途的页面引用，不能立即删除，需要延迟回收。
 *
 * 后台手工清理与本任务共用 Compile 的统一回收入口。手工操作负责固定路径
 * 编译缓存，本任务负责失效巡检与静态指纹垃圾兜底。
 *
 * 调度机制（单任务自循环，对标 PartitionMaintainJob / TempUploadCleanupJob）：
 *   1. 首次由 bin/scheduler 的 bootTasks 自愈创建（dedupeKey: system:compile_cache_recycle_monitor）
 *   2. 执行完成后自动投递下一次任务（次日 04:00），保持链条不断
 *   3. 每日 dedupeKey 确保同一自然日最多只有一个任务实例
 *   4. 链条断裂后，bin/scheduler 重启时 hasActiveTaskOfClass 会重建
 *
 * 回收策略：
 *   - 当前 asset_manifest 引用：绝不删除
 *   - 未引用的静态指纹文件超过浏览器/CDN宽限期后删除
 *   - 删除失败不中断，记日志（异常不静默）
 *
 * 注意：本任务需要 bin/scheduler 常驻进程在运行；调度器未启动时不会触发自动失效，
 * 此时插件 Hook / config.json 改动需通过后台「清除缓存」按钮立即生效。
 *
 * PHP 7.2 兼容。
 */
class CompileCacheRecycleJob implements \Framework\Scheduler\Interfaces\JobInterface
{
    /** @var LoggerInterface */
    private $logger;

    /** @var TaskManage */
    private $taskManage;

    /**
     * @param LoggerInterface $logger
     * @param TaskManage      $taskManage
     */
    public function __construct(LoggerInterface $logger, TaskManage $taskManage)
    {
        $this->logger = $logger;
        $this->taskManage = $taskManage;
    }

    /**
     * 执行指纹文件回收。
     *
     * @param string|null $_task_id 系统注入
     * @return bool true 表示成功，false 触发重试
     */
    public function handle(?string $_task_id = null): bool
    {
        // hook app_Jobs_CompileCacheRecycleJob_handle_start.php
        if (defined('DEBUG') && \DEBUG > 0) {
            $this->logger->info('CompileCacheRecycleJob started', array(
                'task_id' => $_task_id,
            ));
        }

        try {
            $invalidation = $this->checkCompileInvalidation();
            $deleted = $this->recycle();

            $this->logger->info('CompileCacheRecycleJob completed', array(
                'task_id'       => $_task_id,
                'stale'         => $invalidation['stale'],
                'fingerprint'   => $invalidation['fingerprint'],
                'recorded'      => $invalidation['recorded'],
                'epoch'         => $invalidation['epoch'],
                'deleted_files' => $deleted['files'],
                'freed_bytes'   => $deleted['bytes'],
                'errors'        => $deleted['errors'],
                'execution_ms'  => round($deleted['execution_ms'], 2),
            ));

            // hook app_Jobs_CompileCacheRecycleJob_handle_end.php

            // 回收成功才投递下一次任务，避免链条断裂后静默消失
            return $deleted['errors'] === 0 && $this->scheduleNext();
        } catch (\Throwable $e) {
            // 铁律 #25：异常不静默
            $this->logger->error('CompileCacheRecycleJob failed', array(
                'error'   => $e->getMessage(),
                'task_id' => $_task_id,
            ));
            return false;
        }
    }

    /**
     * 显式失效巡检：内容指纹只在后台计算，漂移时发布新的 artifact_epoch 版本标记。
     *
     * 巡检失败不阻断静态资源回收与次日自链：写 epoch / 清缓存属于可重入操作，
     * 下一次任务会重新比对；若在此抛出，handle() 返回 false 会让整条日链在重试耗尽后断掉。
     *
     * @return array{stale:bool,fingerprint:string,recorded:string,epoch:string}
     */
    private function checkCompileInvalidation(): array
    {
        $result = array(
            'stale'       => false,
            'fingerprint' => '',
            'recorded'    => '',
            'epoch'       => '',
        );

        try {
            // 常驻调度器已加载的编译器与类无法随磁盘更新而重载。
            // 仅重建清单，避免把旧进程的构造定义发布到 Web 共用的 container.php；
            // 下一次 Web 启动由 Compile::init($containerCache) 按当前代码生成。
            $result = \App\Core\Compile::publishInvalidationIfStale(null);
        } catch (\Throwable $e) {
            // 铁律 #25：异常不静默
            $this->logger->error('CompileCacheRecycleJob compile invalidation failed', array(
                'error' => $e->getMessage(),
            ));
            return $result;
        }

        if ($result['stale']) {
            $this->logger->info('CompileCacheRecycleJob invalidated stale compile cache', array(
                'recorded_fingerprint' => $result['recorded'],
                'current_fingerprint'  => $result['fingerprint'],
                'artifact_epoch'       => $result['epoch'],
            ));
        }

        return $result;
    }

    /**
     * 删除 public/static/runtime/ 下未被当前清单引用且超过宽限期的指纹文件。
     *
     * @return array
     */
    private function recycle(): array
    {
        $result = \App\Core\Compile::recycleArtifacts();
        foreach ($result['error_messages'] as $message) {
            $this->logger->warning('CompileCacheRecycleJob recycle failed', array(
                'error' => $message,
            ));
        }
        return $result;
    }

    /**
     * 投递下一次回收任务。
     *
     * 使用基于日期的 dedupeKey（job:compile_cache:recycle:daily:20260819），
     * 确保同一自然日最多只有一个等待中的任务。次日 04:00 执行，避让业务高峰期。
     *
     * @return bool
     */
    private function scheduleNext(): bool
    {
        try {
            $now = time();
            $tomorrow = strtotime('tomorrow 04:00');
            if ($tomorrow !== false && $tomorrow > $now) {
                $scheduledAt = $tomorrow;
            } else {
                $scheduledAt = strtotime('+1 day 04:00');
            }

            $dispatch = $this->taskManage->createTask(array(
                'className'   => self::class,
                'methodName'  => 'handle',
                'args'        => array(),
                'priority'    => 10,
                'scheduledAt' => $scheduledAt,
                'dedupeKey'   => 'job:compile_cache:recycle:daily:' . gmdate('Ymd', $scheduledAt),
                'timeout'     => 300,
                'maxRetries'  => 2,
                'retryDelay'  => 60,
            ));

            if (!\Framework\Scheduler\TaskDispatchResult::isAccepted($dispatch)) {
                $this->logger->warning('CompileCacheRecycleJob scheduleNext failed', array(
                    'outcome' => \Framework\Scheduler\TaskDispatchResult::outcome($dispatch),
                    'code' => \Framework\Scheduler\TaskDispatchResult::code($dispatch)
                        ?? 'TASK_DISPATCH_UNKNOWN',
                    'task_id' => \Framework\Scheduler\TaskDispatchResult::taskId($dispatch),
                ));
                return false;
            }

            $this->logger->info('CompileCacheRecycleJob scheduled next', array(
                'scheduled_at' => date('Y-m-d H:i:s', $scheduledAt),
            ));
            return true;
        } catch (\Throwable $e) {
            // TaskManage 不可用时（无 Redis）不抛异常，仅记录
            $this->logger->warning('CompileCacheRecycleJob scheduleNext failed', array(
                'error' => $e->getMessage(),
            ));
            return false;
        }
    }
}
