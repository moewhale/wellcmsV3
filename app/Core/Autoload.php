<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

use App\Core\Compile;

if (!defined('IN_WELLCMS')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo 'Access Denied';
    return;
}

/**
 * 核心自动加载器
 * 使用前缀映射方案支持 src/ app/ core/ 路径与插件、主题类的自动加载
 * 配合 Classmap 生成脚本（bin/generate_classmap.php）日 PV 千万级的生产系统无压力
 * 测试环境：PHP 7.2 + Opcache enabled, 2.5 GHz Intel Core i7, Ubuntu
 */
spl_autoload_register(function ($className) {
    if (class_exists($className, false)) return;

    static $classmap;

    // 惰性加载 Classmap
    if ($classmap === null) {
        $classmapFile = APP_PATH . 'storage/tmp/classmap.php';
        $candidate = [];
        if (is_file($classmapFile)) {
            try {
                $loaded = require $classmapFile;
                $candidate = is_array($loaded) ? $loaded : [];
            } catch (\Throwable $e) {
                $candidate = [];
            }
        }

        // 新格式绑定 release 根；legacy 纯 class=>path 数组继续兼容，但每个条目
        // 仍须经过下方 realpath 根边界验证。
        if (isset($candidate['classes']) && is_array($candidate['classes'])) {
            $root = realpath(APP_PATH);
            $declaredRoot = isset($candidate['app_root']) && is_string($candidate['app_root'])
                ? realpath($candidate['app_root']) : false;
            $epochMatches = true;
            if (array_key_exists('artifact_epoch', $candidate)) {
                $currentEpoch = '';
                $epochFile = APP_PATH . 'storage/tmp/artifact_epoch';
                if (is_file($epochFile)) {
                    $loadedEpoch = file_get_contents($epochFile);
                    $currentEpoch = is_string($loadedEpoch)
                        && preg_match('/^[a-f0-9]{64}$/D', $loadedEpoch) === 1
                        ? $loadedEpoch : null;
                }
                $epochMatches = is_string($candidate['artifact_epoch'])
                    && is_string($currentEpoch)
                    && hash_equals($candidate['artifact_epoch'], $currentEpoch);
            }
            $classmap = $root !== false && $declaredRoot !== false && $root === $declaredRoot
                && $epochMatches
                ? $candidate['classes'] : [];
        } else {
            $classmap = $candidate;
        }
    }

    // Classmap 优先
    if (isset($classmap[$className]) && is_string($classmap[$className])) {
        $mappedFile = realpath($classmap[$className]);
        $appRoot = realpath(APP_PATH);
        $normalizedRoot = $appRoot === false ? '' : rtrim(str_replace('\\', '/', $appRoot), '/') . '/';
        $normalizedFile = $mappedFile === false ? '' : str_replace('\\', '/', $mappedFile);
        $insideCurrentRoot = $normalizedRoot !== ''
            && (DIRECTORY_SEPARATOR === '\\'
                ? stripos($normalizedFile, $normalizedRoot) === 0
                : strpos($normalizedFile, $normalizedRoot) === 0);

        if ($mappedFile !== false && is_file($mappedFile) && $insideCurrentRoot) {
            require Compile::include($mappedFile);
            if (class_exists($className, false)
                || interface_exists($className, false)
                || trait_exists($className, false)) {
                return;
            }
        }
    }

    // 动态加载逻辑（协程安全设计）
    // 固定前缀表不含请求状态，避免每次类加载重复分配嵌套数组。
    static $modules = [
        'App\\' => 'app/',
        'Framework\\' => 'src/',
        'Plugins\\' => 'plugins/',
        'Themes\\' => 'themes/'
    ];

    foreach ($modules as $prefix => $baseDir) {
        $prefixLen = strlen($prefix);
        if (strncmp($className, $prefix, $prefixLen) !== 0) continue;

        $relativeClass = substr($className, $prefixLen);
        $file = APP_PATH . $baseDir . strtr($relativeClass, '\\', '/') . '.php';
        if (!file_exists($file)) continue;

        require Compile::include($file);
        return;
    }
}, true);
