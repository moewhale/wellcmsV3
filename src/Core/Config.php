<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Core;

class Config
{
    /** @var array */
    protected $items = [];

    /**
     * 从目录中加载所有 PHP 配置文件：
     *   - 文件名（不含 .php）作为 $items 的一级键
     *   - 所有已声明分组必需，include 返回值必须是非空数组
     */
    public static function loadDir(): self
    {
        $array = [
            'app'  => 'config/App.php',
            'cache' => 'config/Cache.php',
            'db' => 'config/Database.php',
            'i18n' => 'config/I18n.php',
            'logger' => 'config/Logger.php',
            'plugin' => 'config/Plugin.php',
            'scheduler' => 'config/Scheduler.php',
            'session' => 'config/Session.php',
            'upload' => 'config/Upload.php',
            'view' => 'config/View.php',
        ];

        $cfg = new self;
        foreach ($array as $key => $file) {
            $basePath = \defined('APP_PATH') ? \APP_PATH : \dirname(__DIR__, 2) . '/';
            $configFile = $basePath . $file;
            if (!is_file($configFile)) {
                throw new \Framework\Exception\Infra\ConfigException('Configuration file is missing or is not a regular file: ' . $file);
            }
            try {
                $data = include $configFile;
            } catch (\Throwable $e) {
                throw new \Framework\Exception\Infra\ConfigException('Failed to load configuration file: ' . $file, 0, $e);
            }
            if (!is_array($data)) {
                throw new \Framework\Exception\Infra\ConfigException('Configuration file must return an array: ' . $file . ' (returned ' . gettype($data) . ')');
            }
            if ($data === []) {
                throw new \Framework\Exception\Infra\ConfigException('Configuration file returned an empty array: ' . $file);
            }
            $cfg->items[$key] = $data;
        }

        return $cfg;
    }

    /**
     * 获取某一配置分组，或所有配置
     *
     * @param string|null $group 例如 'db'、'app'，不传返回所有
     * @param mixed       $default
     * @return mixed
     */
    public function get(?string $group = null, $default = null)
    {
        if ($group === null) {
            return $this->items;
        }
        return $this->items[$group] ?? $default;
    }

    /** 返回全部配置，以便注入其他地方 */
    public function all(): array
    {
        return $this->items;
    }
}
