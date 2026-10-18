<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\Controllers\Base;

use Framework\Http\Interfaces\ServerRequestInterface;

class TemplateManager
{
    /** @var \Framework\Cache\Interfaces\CacheInterface */
    protected $cache;
    /** @var string */
    protected $themesPath;
    /** @var string */
    protected $pluginsPath;
    /** @var array */
    protected $devicePrefixes;
    /** @var array|null */
    protected $templateMap = null;
    /** @var string */
    protected $mapPath;
    /** @var array|null 当前 Worker 固定的主题解析上下文 */
    protected $themeContext = null;
    /** @var string */
    protected $themeIdentity = '';

    public function __construct(\Framework\Cache\Interfaces\CacheInterface $cache, string $themesPath = '', string $pluginsPath = '', array $devicePrefixes = [])
    {
        $this->cache = $cache;
        $this->themesPath = $themesPath;
        $this->pluginsPath = $pluginsPath;
        $this->devicePrefixes = $devicePrefixes;
        $this->mapPath = $this->resolveMapPath();
    }

    /**
     * 获取模板文件绝对路径
     *
     * @param bool|string $templateDir false:前台; true:后台; string:插件名
     * @param string $fileName 模板名
     * @param string $id 绑定对象ID
     * @param ServerRequestInterface|null $request 当前请求对象，用于协程安全缓存
     * @return string
     */
    public function template($templateDir= false, string $fileName = '', ?string $id = '', ?ServerRequestInterface $request = null): string
    {
        // 1. 尝试 L1 缓存：请求级内存缓存 (PSR-7 Attribute)
        if ($request) {
            $requestCache = $request->getAttribute('_tpl_path_cache', []);
            $cacheKey = (string)$templateDir . $fileName . $id;
            if (isset($requestCache[$cacheKey])) return $requestCache[$cacheKey];
        }

        // 2. 尝试 L2 缓存：持久化路径映射 (template_map.php)
        if (null === $this->templateMap) {
            $this->templateMap = (!defined('DEBUG') || \DEBUG === 0)
                ? $this->readPersistedMap()
                : [];
        }

        $this->loadThemeContext();
        $mapInnerKey = $this->themeIdentity . ':' . (string)$templateDir . ':'
            . $fileName . ':' . $id . ':' . $this->getDevicePrefix($request);
        if (isset($this->templateMap[$mapInnerKey]) && file_exists($this->templateMap[$mapInnerKey])) {
            return $this->templateMap[$mapInnerKey];
        }

        // 3. 执行物理探测逻辑
        $filePath = $this->resolvePhysicalPath($templateDir, $fileName, $id, $request);
        if (empty($filePath)) {
            throw new \RuntimeException('Template not found: ' . $fileName);
        }

        // 4. 更新缓存
        if (!empty($filePath)) {
            $this->templateMap[$mapInnerKey] = $filePath;
            // 如果处于非调试模式或特定条件下，持久化缓存
            if (!defined('DEBUG') || \DEBUG === 0) {
                $this->persistMap();
            }
        }

        return $filePath;
    }

    /**
     * @param bool|string $templateDir false:前台; true:后台; string:插件名
     * @param string $fileName 模板名
     * @param string|null $id 绑定对象ID（可选）
     */
    protected function resolvePhysicalPath($templateDir, string $fileName, ?string $id, ServerRequestInterface $request): string
    {
        $config = $this->loadThemeContext();
        $theme = isset($config['themes']['theme']) ? $config['themes']['theme'] : '';
        $prefix = $this->getDevicePrefix($request);
        $templateFile = $prefix . $fileName . '.htm';

        if ($theme) {
            $themeChildren = !empty($config['theme_children']) ? $config['theme_children'] : [];
            $paths = $this->generatePaths($templateFile, $id, $theme, $themeChildren);
            foreach ($paths as $path) {
                if (file_exists($path)) return $path;
            }
        }

        // 插件路径检测
        if (is_string($templateDir)) {
            $path = $this->pluginsPath . $templateDir . '/views/htm/' . $fileName . '.htm';
            if (file_exists($path)) return $path;
        }

        // 核心默认路径
        $defaultPath = true === $templateDir
            ? APP_PATH . 'app/views/admin/' . $fileName . '.htm'
            : APP_PATH . 'app/views/htm/' . $fileName . '.htm';

        // 若默认路径也不存在，则返回空字符串，避免缓存不存在路径
        if (!file_exists($defaultPath)) {
            return '';
        }

        return $defaultPath;
    }

    /**
     * 主题在 FPM 请求或 Swoole Worker 生命周期内固定；切换主题后由既有
     * Runtime::reload 创建新 Worker。这里只读取一次 setting，避免模板热路径
     * 为 L2 identity 重复访问缓存。
     */
    private function loadThemeContext(): array
    {
        if ($this->themeContext !== null) {
            return $this->themeContext;
        }

        $setting = $this->cache->get('setting') ?? [];
        $this->themeContext = is_array($setting) ? $setting : [];
        $theme = (string)($this->themeContext['themes']['theme'] ?? '');
        $children = $this->themeContext['theme_children'] ?? [];
        $children = is_array($children) ? $children : [];
        $this->themeIdentity = hash('sha256', $theme . "\0" . serialize($children));
        return $this->themeContext;
    }

    protected function getDevicePrefix(?ServerRequestInterface $request = null): string
    {
        $ua = $request
            ? ($request->getServerParams()['HTTP_USER_AGENT'] ?? '')
            : '';

        $uaLower = strtolower($ua);
        if (strpos($uaLower, 'phone') !== false || strpos($uaLower, 'mobile') !== false || strpos($uaLower, 'ipod') !== false) {
            return $this->devicePrefixes['mobile'] ?? '';
        }
        if (strpos($uaLower, 'pad') !== false || strpos($uaLower, 'tablet') !== false) {
            return $this->devicePrefixes['tablet'] ?? '';
        }
        return '';
    }

    /**
     * 生成可能的模板路径列表，优先级从高到低
     * @param string $templateFile 模板文件名（含设备前缀）
     * @param string|null $id 绑定对象ID（可选）
     * @param string $theme 当前主题
     * @param array $themeChildren 主题子项列表，包含子主题信息和日期（用于排序）
     */
    protected function generatePaths(string $templateFile, ?string $id, string $theme, array $themeChildren): array
    {
        $paths = [];
        if (!empty($themeChildren)) {
            uasort($themeChildren, function ($a, $b) {
                return ($b['date'] ?? 0) - ($a['date'] ?? 0);
            });
            foreach ($themeChildren as $child) {
                if (!empty($child['theme'])) {
                    $dir = $this->themesPath . $child['theme'] . '/htm/';
                    if ($id) $paths[] = $dir . $id . '_' . $templateFile;
                    $paths[] = $dir . $templateFile;
                }
            }
        }

        if ($theme) {
            $dir = $this->themesPath . $theme . '/htm/';
            if ($id) $paths[] = $dir . $id . '_' . $templateFile;
            $paths[] = $dir . $templateFile;
        }

        return $paths;
    }

    protected function persistMap(): void
    {
        $dir = dirname($this->mapPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create template map directory: {$dir}");
        }
        if (is_dir($this->mapPath)) {
            throw new \RuntimeException("Template map target is not a file: {$this->mapPath}");
        }

        $lockPath = $this->mapPath . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException("Unable to open template map lock: {$lockPath}");
        }

        $locked = false;
        $temporary = '';
        $fp = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock template map: {$lockPath}");
            }
            $locked = true;

            // 每个 Worker 都可能持有旧快照；锁内重读并合并，避免最后写者丢键。
            $map = array_merge(
                $this->readPersistedMap(),
                $this->normalizeMap($this->templateMap)
            );
            $content = "<?php\nreturn " . var_export($map, true) . ";\n";

            $temporaryPath = tempnam($dir, '.wellcms-template-map-');
            if ($temporaryPath === false || realpath(dirname($temporaryPath)) !== realpath($dir)) {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
                throw new \RuntimeException("Unable to create template map temporary file: {$this->mapPath}");
            }
            $temporary = $temporaryPath;

            $fp = fopen($temporary, 'wb');
            if ($fp === false) {
                throw new \RuntimeException("Unable to open template map temporary file: {$temporary}");
            }
            $length = strlen($content);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($fp, substr($content, $offset, 1048576));
                if (!is_int($written) || $written <= 0) {
                    throw new \RuntimeException("Unable to write complete template map: {$this->mapPath}");
                }
                $offset += $written;
            }
            if (!fflush($fp)) {
                throw new \RuntimeException("Unable to flush template map temporary file: {$this->mapPath}");
            }
            if (function_exists('fsync') && !fsync($fp)) {
                throw new \RuntimeException("Unable to synchronize template map temporary file: {$this->mapPath}");
            }

            $permissions = is_file($this->mapPath) ? fileperms($this->mapPath) : false;
            $mode = is_int($permissions) ? ($permissions & 0777) : (0666 & ~umask());
            if (!chmod($temporary, $mode)) {
                throw new \RuntimeException("Unable to set template map permissions: {$this->mapPath}");
            }

            fclose($fp);
            $fp = null;
            if (!@rename($temporary, $this->mapPath)) {
                throw new \RuntimeException("Unable to atomically publish template map: {$this->mapPath}");
            }
            $temporary = '';
            $this->templateMap = $map;
        } catch (\Throwable $exception) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
            throw $exception;
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /**
     * 生成代内共享映射，滚动发布时不与旧 Swoole Worker 共写同一文件。
     */
    private function resolveMapPath(): string
    {
        $basePath = APP_PATH . 'storage/tmp/template_map';
        $epochPath = APP_PATH . 'storage/tmp/artifact_epoch';
        if (!is_file($epochPath)) {
            return $basePath . '.php';
        }

        $epoch = file_get_contents($epochPath);
        if (!is_string($epoch) || preg_match('/^[a-f0-9]{64}$/D', $epoch) !== 1) {
            throw new \RuntimeException("Invalid template map artifact epoch: {$epochPath}");
        }
        return $basePath . '.' . $epoch . '.php';
    }

    /**
     * L2 是可重建的加速数据；旧版截断文件不得升级为请求 Fatal。
     */
    private function readPersistedMap(): array
    {
        if (!is_file($this->mapPath)) {
            return [];
        }
        try {
            $map = @include $this->mapPath;
        } catch (\Throwable $exception) {
            return [];
        }
        return $this->normalizeMap($map);
    }

    /**
     * @param mixed $map
     */
    private function normalizeMap($map): array
    {
        if (!is_array($map)) {
            return [];
        }
        $normalized = [];
        foreach ($map as $key => $path) {
            if (is_string($key) && is_string($path)) {
                $normalized[$key] = $path;
            }
        }
        return $normalized;
    }
}
