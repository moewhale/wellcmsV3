<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

/* =========================================================
 | 2. Permission —— RBAC 权限检查
 |    - 从构造函数接受权限数组
 |    - user 无权限则 403
 * =======================================================*/
class UserPermMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var \Framework\Core\Container */
    private $container;
    /** @var array */
    private $params;

    public function __construct(\Framework\Core\Container $container, $params = null)
    {
        $this->container = $container;
        $this->params = $params;
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        try {
            $user = $request->getAttribute('user');

            $groupId = (int)($user['group_id'] ?? 0);

            if (!empty($this->params['role'])) {
                $groupService = $this->container->get(\App\Services\Auth\GroupService::class);
                if (is_array($this->params['role'])) {
                    $hasPerm = false;
                    foreach ($this->params['role'] as $role) {
                        if ($groupService->access($groupId, $role)) {
                            $hasPerm = true;
                            break;
                        }
                    }

                    if (!$hasPerm) return $this->container->get(\App\Controllers\Base\MessageController::class)->errorMessage('Access Denied', 8);

                } else {
                    if (!$groupService->access($groupId, $this->params['role'])) return $this->container->get(\App\Controllers\Base\MessageController::class)->errorMessage('Access Denied', 8);
                }
            }

            return $handler->handle($request);
        } catch (\Throwable $e) {
            $logger = $this->container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . get_class($this) . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if (\defined('DEBUG') && \DEBUG >= 2) throw $e;

            $messageController = $this->container->get(\App\Controllers\Base\MessageController::class);
            return $messageController->errorMessage('User Permission verification error: ' . $e->getMessage(), 500);
        }
    }
}
