<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Providers;

class ModelServiceProvider implements \Framework\Providers\ServiceProviderInterface
{
    public function register(\Framework\Core\Container $container): void
    {
        try {

            // hook app_Providers_ModelServiceProvider_register_start.php

            $models = [
                \App\Models\CacheModel::class,
                \App\Models\GroupModel::class,
                \App\Models\IpListModel::class,
                \App\Models\KeyValueModel::class,
                \App\Models\LogModel::class,
                \App\Models\NavigationModel::class,
                \App\Models\RecycleModel::class,
                \App\Models\SchedulerTaskModel::class,
                \App\Models\SessionDataModel::class,
                \App\Models\SessionModel::class,
                \App\Models\UserModel::class,
                \App\Models\FileStorageModel::class,
                \App\Models\AttachmentModel::class,
                \App\Models\TempContentModel::class,
                \App\Models\UploadLogModel::class
            ];

            // 核心模型按需创建真实单例；插件仍可在统一列表内选择加入或取消标记。
            $deferredModels = array_fill_keys($models, true);
            // hook app_Providers_ModelServiceProvider_register_models.php

            foreach ($models as $model) {
                if (!isset($deferredModels[$model])) {
                    $container->bind($model, $model, true, false);
                    continue;
                }
                $container->bind($model, static function ($container, array $params) use ($model) {
                    return $container->make($model, $params);
                }, true, !defined('SWOOLE_MODE') || !\SWOOLE_MODE);
            }

            // 保留基础服务的统一列表，只对已核对初始化行为的外围服务启用按需创建。
            $coreServices = [
                \App\Services\System\CacheService::class,
                \App\Services\Auth\GroupService::class,
                \App\Services\System\IpListService::class,
                \App\Services\System\KeyValueService::class,
                \App\Services\System\LogService::class,
                \App\Services\Auth\SessionDataService::class,
                \App\Services\Auth\SessionService::class,
                \App\Services\Auth\UserService::class,
                \App\Services\Auth\TokenService::class,
                \App\Services\Auth\EmailVerificationService::class,
                \App\Services\Storage\FileStorageService::class,
                \App\Services\Storage\AttachmentService::class,
                \App\Services\Storage\DownloadService::class,
                \App\Services\Content\TempContentService::class,
                \App\Services\Content\NavigationService::class,
            ];

            $deferredCoreServices = array_fill_keys([
                \App\Services\System\CacheService::class,
                \App\Services\Auth\SessionDataService::class,
                \App\Services\System\LogService::class,
                \App\Services\Auth\EmailVerificationService::class,
                \App\Services\Storage\FileStorageService::class,
                \App\Services\Storage\AttachmentService::class,
                \App\Services\Storage\DownloadService::class,
                \App\Services\Content\TempContentService::class,
            ], true);

            // hook app_Providers_ModelServiceProvider_register_core_services.php

            foreach ($coreServices as $service) {
                if (!isset($deferredCoreServices[$service])) {
                    $container->bind($service, $service, true, false);
                    continue;
                }
                $container->bind($service, static function ($container, array $params) use ($service) {
                    return $container->make($service, $params);
                }, true, !defined('SWOOLE_MODE') || !\SWOOLE_MODE);
            }

            $moduleServices = [
                \App\Services\Content\RecycleService::class,
                \App\Services\Storage\UploadLogService::class,
                \App\Services\System\MailService::class,
            ];

            // hook app_Providers_ModelServiceProvider_register_services.php

            foreach ($moduleServices as $service) {
                $container->bind($service, $service, true, true);
            }

            // 含具体类型约束的模块服务按需返回真实单例。
            $moduleServicesEager = [
                \App\Services\ExternalLinkService::class,
                \App\Services\LinkService::class,
            ];

            // hook app_Providers_ModelServiceProvider_register_module_services_eager.php

            foreach ($moduleServicesEager as $service) {
                $container->bind($service, static function ($container, array $params) use ($service) {
                    return $container->make($service, $params);
                }, true, !defined('SWOOLE_MODE') || !\SWOOLE_MODE);
            }

            // 特殊构造服务
            $container->bind(\App\Controllers\Admin\Service\TokenManager::class, function ($container) {
                return new \App\Controllers\Admin\Service\TokenManager(
                    $container->get('appConfig'),
                    $container->get('sessionConfig')
                );
            }, true, true);

            // TempCleanupService: 含 array $uploadConfig 非类型化参数，需闭包显式注入
            $container->bind(\App\Services\Storage\TempCleanupService::class, function ($container) {
                return new \App\Services\Storage\TempCleanupService(
                    $container->get('uploadConfig'),
                    $container->get(\Framework\Logger\LoggerInterface::class),
                    $container->get(\Framework\Cache\Interfaces\CacheInterface::class),
                    $container->get(\App\Services\Content\TempContentService::class),
                    $container
                );
            }, true, true);

            // hook app_Providers_ModelServiceProvider_register_after.php

            // 有依赖渲染 (不延迟加载，避免 Proxy 导致构造函数类型校验失败)
            $container->bind(\Framework\Cache\Interfaces\CacheInterface::class, static function ($container) {
                return new \App\Factory\CacheFactory($container, $container->get('cacheConfig'));
            }, true, false);

            // hook app_Providers_ModelServiceProvider_register_end.php

        } catch (\Throwable $e) {
            $logger = $container->get(\Framework\Logger\LoggerInterface::class);
            $logger->error("Error in " . self::class . ": " . $e->getMessage());
            // 如果是 DEBUG 模式，就把异常抛出来，方便前端看到
            if ((\DEBUG ?? 0) >= 2) throw $e;
        }
    }

    public function boot(\Framework\Core\Container $container): void
    {
        // nothing
    }
}
