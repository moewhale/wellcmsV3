<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Factory;

class CacheFactory extends \Framework\Cache\CacheManager
{
    public function __construct(\Framework\Core\Container $container, array $cacheConfig)
    {
        parent::__construct($cacheConfig);
        // 若缓存配置列表为空，则使用mysql缓存。
        if (empty($cacheConfig['stores'])) $this->drivers['mysql'] = $container->get(\App\Services\System\CacheService::class);
    }
}
