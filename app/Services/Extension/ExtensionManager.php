<?php

declare(strict_types=1);

namespace App\Services\Extension;

use Framework\Utils\SecurityHelper;

/**
 * ExtensionManager - 插件与主题的生命周期抽象管理
 */
class ExtensionManager
{
    /** @var \App\Services\System\KeyValueService */
    private $kv;
    /** @var \App\Interfaces\LanguageLoaderInterface */
    private $language;
    /** @var \App\Services\Market\MarketClient */
    private $market;
    /** @var \Framework\Http\Routing\UrlGeneratorInterface */
    private $urlGenerator;
    /** @var \App\Services\Extension\ExtensionInstaller */
    private $installer;
    /** @var \Framework\Core\Container */
    protected $container;
    /** @var array */
    private $appConfig;
    /** @var string */
    private $pluginPath;
    /** @var string */
    private $themePath;
    /** @var int */
    private const SYNC_TTL = 3600;

    public function __construct(
        array $appConfig,
        array $pluginConfig,
        array $viewConfig,
        \App\Services\System\KeyValueService $kv,
        ?\App\Interfaces\LanguageLoaderInterface $language,
        \App\Services\Market\MarketClient $market,
        \Framework\Http\Routing\UrlGeneratorInterface $urlGenerator,
        \App\Services\Extension\ExtensionInstaller $installer,
        ?\Framework\Core\Container $container = null
    ) {
        $this->container = $container;
        $this->kv = $kv;
        $this->language = $language;
        $this->market = $market;
        $this->urlGenerator = $urlGenerator;
        $this->installer = $installer;
        $this->appConfig = $appConfig;

        $this->pluginPath = APP_PATH . 'plugins/';
        $this->themePath = APP_PATH . 'themes/';

        // 如果配置中有更准确的物理路径，尝试使用它
        if (!empty($pluginConfig['plugins_path']) && strpos($pluginConfig['plugins_path'], APP_PATH) === 0) {
            $this->pluginPath = $pluginConfig['plugins_path'];
        }
        if (!empty($viewConfig['themes_path']) && strpos($viewConfig['themes_path'], APP_PATH) === 0) {
            $this->themePath = $viewConfig['themes_path'];
        }

        // 强制标准化末尾 "/"
        $this->pluginPath = rtrim($this->pluginPath, '/\\') . DIRECTORY_SEPARATOR;
        $this->themePath = rtrim($this->themePath, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function isLocal(string $dir, string $type): bool
    {
        return is_dir($this->getBasePath($type) . $dir);
    }

    public function getIconUrl(string $dir, string $type, string $remoteIcon = ''): string
    {
        // 1. 优先本地已安装图标
        $path = ($type === 'theme' ? 'themes/' : 'plugins/') . $dir . '/icon.png';
        if (file_exists(APP_PATH . $path)) {
            return $this->appConfig['path'] . $path;
        }

        // 2. 回退到服务端远程图标（未安装时）
        if (!empty($remoteIcon)) {
            // 相对路径兜底：拼接网站根路径，避免浏览器按当前 URL 解析
            if (strpos($remoteIcon, 'http') !== 0 && strpos($remoteIcon, '/') !== 0) {
                return rtrim($this->appConfig['path'], '/') . '/' . ltrim($remoteIcon, '/');
            }
            return $remoteIcon;
        }

        return '';
    }

    public function formatList(array $list): array
    {
        $newList = [];
        foreach ($list as $dir => $item) {
            // 通过 readByDir 合并本地状态（downloaded/installed/enable），确保按钮链接正确
            $type = (2 === (int)($item['type'] ?? 0)) ? 'theme' : 'plugin';
            $fullItem = $this->readByDir((string)$dir, $type, true);
            if (!empty($fullItem)) {
                // 保留官方数据中的 last_sync 等字段
                $fullItem = array_merge($item, $fullItem);
                // 显示名称以列表数据源为准，避免本地配置或目录名默认值覆盖商店名称。
                $fullItem['name'] = $item['name'];
            } else {
                $fullItem = $item;
            }
            $fullItem['operation_links'] = $this->buildOperationLinks($fullItem);
            $fullItem['icon_url'] = $this->getIconUrl((string)$dir, $type, $item['icon'] ?? '');
            $newList[$dir] = $fullItem;
        }

        // 处理主题的父子继承关系 (Variant Themes)
        foreach ($newList as $dir => $item) {
            if (2 === (int)$item['type'] && !empty($item['dependencies_theme'])) {
                $parentDir = key($item['dependencies_theme']);
                if (isset($newList[$parentDir])) {
                    $newList[$parentDir]['children'][$dir] = $item;
                    unset($newList[$dir]);
                }
            }
        }

        return $newList;
    }

    public function buildOperationLinks(array $data): array
    {
        $type = (2 === (int)$data['type']) ? 'theme' : 'plugin';
        $dir = $data['dir'] ?? '';
        $links = ['detail' => '', 'download' => '', 'install' => '', 'uninstall' => '', 'setting' => '', 'enable' => '', 'disable' => '', 'upgrade' => ''];

        if (!$dir) return $links;

        $extra = ['dir' => $dir];
        if (!empty($data['storeid'])) {
            $extra['storeid'] = $data['storeid'];
        }
        if ($data['downloaded']) {
            $links['detail'] = [
                'label' => $this->language->get('detail'),
                'url' => $this->urlGenerator->url('admin/' . $type . '/detail', $extra)
            ];

            // 主题仅未安装时显示 install（已安装时只显示 uninstall）
            if ('theme' === $type) {
                if (0 === (int)$data['installed']) {
                    $links['install'] = ['label' => $this->language->get('install'), 'url' => $this->urlGenerator->url('admin/theme/postInstall', $extra)];
                }
            } else {
                $links['install'] = (0 === (int)$data['installed']) ? ['label' => $this->language->get('install'), 'url' => $this->urlGenerator->url('admin/plugin/postInstall', $extra)] : '';
            }

            if (1 === (int)$data['installed']) {
                $links['uninstall'] = ['label' => $this->language->get('uninstall'), 'url' => $this->urlGenerator->url('admin/' . $type . '/postUninstall', $extra)];

                $settingFile = $this->getBasePath($type) . $dir . '/setting.php';
                if (file_exists($settingFile)) {
                    $links['setting'] = ['label' => $this->language->get('setting'), 'url' => $this->urlGenerator->url('admin/' . $type . '/setting', $extra)];
                }
            }

            if ('plugin' === $type && 1 === (int)$data['installed']) {
                $links['enable'] = (0 === (int)$data['enable']) ? ['label' => $this->language->get('enable'), 'url' => $this->urlGenerator->url('admin/plugin/postEnable', $extra)] : '';

                $links['disable'] = (1 === (int)$data['enable']) ? ['label' => $this->language->get('disable'), 'url' => $this->urlGenerator->url('admin/plugin/postDisable', $extra)] : '';
            }

            // 主题无启用/禁用概念，通过 install 切换激活

            $links['upgrade'] = (1 === (int)$data['installed'] && ($data['has_upgrade'] ?? false)) ? ['label' => $this->language->get('upgrade'), 'url' => $this->urlGenerator->url('admin/store/upgrade', $extra)] : '';
        } else {
            // 本地未下载：显示商店详情 + 下载/购买
            $links['detail'] = ['label' => $this->language->get('detail'), 'url' => $this->urlGenerator->url('admin/store/detail', $extra)];

            if ((int)$data['price'] > 0) {
                // 付费插件：已购买显示下载，未购买显示购买
                // P1 FIX: 统一使用 payment_id 判断购买状态，与 StoreController::detail() 保持一致
                if (!empty($data['payment_id'])) {
                    $links['download'] = ['label' => $this->language->get('download'), 'url' => $this->urlGenerator->url('admin/store/download', $extra)];
                } else {
                    $links['download'] = ['label' => $this->language->get('buy') ?: $this->language->get('payment') ?: 'Buy', 'url' => $this->urlGenerator->url('admin/store/payment', $extra)];
                }
            } else {
                // 免费插件：直接下载
                $links['download'] = ['label' => $this->language->get('download'), 'url' => $this->urlGenerator->url('admin/store/download', $extra)];
            }
        }

        // 防御：确保所有链接数组包含 confirm 键，防止模板直接访问时 Undefined array key
        foreach ($links as &$link) {
            if (is_array($link) && !isset($link['confirm'])) {
                $link['confirm'] = '';
            }
        }
        unset($link);

        return $links;
    }

    public function buildSearchTypes(): array
    {
        return [
            ['field' => 'name', 'label' => $this->language->get('name')],
            ['field' => 'author', 'label' => $this->language->get('author')]
        ];
    }

    public function buildPagination(int $page, int $pageSize, int $total, array $extra = [], string $route = ''): array
    {
        $totalPages = (int)ceil($total / $pageSize);
        $prev = '';
        $next = '';

        if ($page > 1) {
            $extra['page'] = $page - 1;
            $prev = $this->urlGenerator->url($route, $extra);
        }
        if ($page < $totalPages) {
            $extra['page'] = $page + 1;
            $next = $this->urlGenerator->url($route, $extra);
        }

        return [
            'current' => $page,
            'total' => $totalPages,
            'previous_link' => $prev,
            'next_link' => $next,
        ];
    }

    /**
     * 支付成功后强制单条同步（绕过 TTL / 防抖）
     * 使用独立锁名 'market:payment:$dir'，避免与 syncMarketData 竞争
     */
    public function forceSyncSingle(string $dir): void
    {
        if (!$this->market->isLogged()) {
            throw new \RuntimeException('Store credentials are required for purchase synchronization');
        }

        $completed = $this->withSyncLock('market:payment:' . $dir, function () use ($dir) {
            $officialData = (array)($this->kv->settingGet('officialData') ?? []);
            $marketData = $this->market->queryExtensions(
                $this->buildMarketQueryItems([$dir], $officialData)
            );
            $data = $marketData[$dir] ?? null;
            if (!is_array($data)
                || !hash_equals($dir, (string)($data['dir'] ?? ''))
                || !in_array((int)($data['type'] ?? 0), [1, 2], true)
                || empty($data['name'])) {
                throw new \RuntimeException('Purchased extension was not returned by the store');
            }
            if (empty($data['has_bought']) || (int)($data['payment_id'] ?? 0) <= 0) {
                throw new \RuntimeException('Store purchase projection was not committed');
            }
            $data['last_sync'] = time();
            $officialData[$dir] = array_merge($officialData[$dir] ?? [], $data);
            if (!$this->kv->settingSet('officialData', $officialData)) {
                throw new \RuntimeException('Failed to persist purchased extension metadata');
            }
        });
        if (!$completed) {
            throw new \RuntimeException('Purchase synchronization is already in progress');
        }
    }

    /**
     * 获取扩展的 storeid。
     */
    public function getStoreId(string $dir): int
    {
        $officialData = (array)($this->kv->settingGet('officialData') ?? []);
        return (int)($officialData[$dir]['storeid'] ?? 0);
    }

    public function getBasePath(string $type): string
    {
        return $type === 'theme' ? $this->themePath : $this->pluginPath;
    }

    /**
     * 官方数据优先字段白名单
     * 这些字段代表商店权威数据，本地 config.json 无权覆盖
     */
    private const OFFICIAL_PRIORITY_FIELDS = [
        'storeid', 'price', 'payment_id', 'version', 'downloads',
        'rating', 'rating_count', 'is_official', 'has_upgrade',
        'cloud_version', 'last_update', 'screenshots', 'tags',
        'has_bought', 'is_paid', 'type', 'icon',
    ];

    public function readByDir(string $dir, string $type, bool $localFirst = true): array
    {
        $basePath = $this->getBasePath($type);
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';

        $local = [];
        if (file_exists($configFile)) {
            $json = (string)file_get_contents($configFile);
            $local = SecurityHelper::jsonDecode($json) ?? [];
        }

        $officialData = $this->kv->settingGet('officialData') ?? [];
        $official = $officialData[$dir] ?? [];

        if (empty($local) && empty($official)) return [];

        $default = [
            'name' => $dir,
            'price' => 0,
            'brief' => '',
            'version' => '1.0.0',
            'software_version' => '3.0',
            'installed' => 0,
            'enable' => 0,
            'hooks' => [],
            'dependencies' => [],
            'author' => 'Official',
            'type' => $type === 'theme' ? 2 : 1,
            'dir' => $dir,
            'downloads' => 0,
            'last_update' => '',
        ];

        $local = array_merge($default, $local);
        $official = array_merge(['storeid' => 0, 'upgrade' => 0], $official);

        // ─── 核心合并逻辑：官方白名单字段强制优先 ───
        // FIX: 在官方覆盖前保存本地版本号，用于后续 has_upgrade 计算
        $localVersion = (string)($local['version'] ?? '1.0.0');
        $extension = $local;

        // 官方优先字段：无论 localFirst 如何，始终由官方覆盖
        foreach (self::OFFICIAL_PRIORITY_FIELDS as $field) {
            if (array_key_exists($field, $official)) {
                $extension[$field] = $official[$field];
            }
        }

        // 当本地数据缺失时，完全以官方数据补充
        if (empty($local['name']) || $local['name'] === $dir) {
            $extension = array_merge($official, $extension);
        }

        // 当明确要求官方优先时（商店详情页），官方数据整体再覆盖一次
        if (!$localFirst) {
            $extension = array_merge($extension, $official);
        }

        // 主题启用状态特殊修正：以全局 config.theme 为准
        if ($type === 'theme') {
            $settingConfig = $this->kv->settingGet('config') ?? [];
            if (($settingConfig['theme'] ?? '') === $dir) {
                $extension['enable'] = 1;
            }
        }

        $extension['downloaded'] = file_exists($configFile);
        $extension['cloud_version'] = (string)($official['version'] ?? $extension['version']);
        // FIX: 使用本地原始版本号与云端版本比较，避免官方覆盖后 version == cloud_version 导致 has_upgrade 永远为 false
        $extension['has_upgrade'] = (true === ($official['has_upgrade'] ?? null))
            ? 1
            : (int)(($extension['installed'] && version_compare($localVersion, (string)$extension['cloud_version'], '<')) ? 1 : 0);

        return $extension;
    }

    public function getLocalList(string $type): array
    {
        $basePath = $this->getBasePath($type);
        if (!is_dir($basePath)) return [];

        $dirs = scandir($basePath);
        $list = [];
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            if (is_dir($basePath . $dir)) {
                $info = $this->readByDir($dir, $type, true);
                if ($info) $list[$dir] = $info;
            }
        }
        return $list;
    }

    public function updateStatus(string $dir, string $type, string $action): array
    {
        $basePath = $this->getBasePath($type);
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';
        if (!file_exists($configFile)) return ['status' => 'error', 'message' => 'config.json missing'];

        $json = (string)file_get_contents($configFile);
        $config = SecurityHelper::jsonDecode($json);

        switch ($action) {
            case 'enable':
                $config['enable'] = 1;
                break;
            case 'disable':
                $config['enable'] = 0;
                break;
            case 'install':
            case 'upgrade':
                $config['installed'] = 1;
                $config['enable'] = 1;
                break;
            case 'uninstall':
                $config['installed'] = 0;
                $config['enable'] = 0;
                break;
        }

        $this->writeConfigAtomically($configFile, SecurityHelper::jsonEncode($config, true));
        try {
            $this->runActionScript($dir, $type, $action);
            if ($type === 'plugin' && $action === 'upgrade') {
                $this->container->get(\Framework\Database\Partition\PartitionRegistry::class)
                    ->invalidateAllPhysicalPartitions();
            }
        } catch (\Throwable $e) {
            $this->writeConfigAtomically($configFile, $json);
            throw $e;
        }
        return ['status' => 'success'];
    }

    public function execute(string $dir, string $type, string $action): array
    {
        $localList = $this->getLocalList($type);
        if (!isset($localList[$dir])) return ['status' => 'error', 'message' => 'Extension not found'];

        $name = $localList[$dir]['name'];
        $fileLockKey = $this->getBasePath($type) . $dir;
        if (!\Framework\Utils\FileHelper::lock($fileLockKey)) {
            return ['status' => 'error', 'message' => 'Extension operation is already in progress'];
        }
        try {
            $missing = $this->checkDependencies($dir, $type, $action);
            if ($missing) {
                $list = implode(', ', array_keys($missing));
                $msg = in_array($action, ['install', 'enable', 'upgrade'])
                    ? $this->language->get('extension_dependency_following', ['s' => $list])
                    : $this->language->get('extension_being_dependent_cant_delete', ['name' => $name, 's' => $list]);
                return ['status' => 'error', 'message' => $msg];
            }

            $res = $this->updateStatus($dir, $type, $action);
            if ($res['status'] === 'error') return $res;

            // 主题状态变更同步至 KV 层（须在编译缓存清理前完成）
            if ($type === 'theme') {
                $config = $this->kv->settingGet('config') ?? [];
                if ($action === 'install') {
                    // ★ 旧主题降权：切换时标记为未安装（直接写 config.json，不跑脚本）
                    $oldTheme = $config['theme'] ?? '';
                    if ($oldTheme !== '' && $oldTheme !== $dir) {
                        // 不卸载依赖关系：新主题依赖旧主题时（如子主题安装），保留父主题
                        $newDeps = $localList[$dir]['dependencies_theme'] ?? [];
                        $dependsOnOld = isset($newDeps[$oldTheme]) || in_array($oldTheme, $newDeps);
                        if (!$dependsOnOld) {
                            $this->markThemeUninstalled($oldTheme);
                        }
                    }
                    $config['theme'] = $dir;
                    $this->kv->settingSet('config', $config);
                } elseif ($action === 'uninstall') {
                    if (($config['theme'] ?? '') === $dir) {
                        // 构建安全回退候选池：已安装且独立（无父主题依赖）
                        $themeList = $this->getLocalList('theme');
                        $fallbackDir = '';
                        foreach ($themeList as $tDir => $tInfo) {
                            if (empty($tInfo['installed']) || !empty($tInfo['dependencies_theme'])) {
                                continue;
                            }
                            if ($tDir === 'well_demo') {
                                $fallbackDir = $tDir;
                                break;
                            }
                            if ($fallbackDir === '') {
                                $fallbackDir = $tDir;
                            }
                        }
                        $config['theme'] = $fallbackDir;
                        try {
                            $this->kv->settingSet('config', $config);
                        } catch (\Exception $e) {
                            if ($this->container) {
                                try {
                                    $this->container->get(\Framework\Logger\LoggerInterface::class)->error('Theme uninstall KV update failed: ' . $e->getMessage());
                                } catch (\Throwable $t) {
                                    error_log('Theme uninstall KV update failed: ' . $e->getMessage());
                                }
                            }
                        }
                        if ($fallbackDir === '') {
                            if ($this->container) {
                                try {
                                    $this->container->get(\Framework\Logger\LoggerInterface::class)->critical('Theme fallback failed: no available independent theme after uninstalling ' . $dir);
                                } catch (\Throwable $t) {
                                    error_log('[CRITICAL] Theme fallback failed: no available independent theme after uninstalling ' . $dir);
                                }
                            }
                        }
                    }
                }
            }

            // 插件/主题状态变更，强制清除全站编译缓存（重触发聚合、钩子注入和语言包编译）
            \App\Core\Compile::flushCache();

            \Framework\Utils\Runtime::reload();
            return ['status' => 'success', 'name' => $name];
        } finally {
            \Framework\Utils\FileHelper::unlock($fileLockKey);
        }
    }

    public function deploy(string $dir, string $type, int $storeId): array
    {
        if (!$this->container) {
            throw new \RuntimeException('Extension deployment requires the application container');
        }
        $lockKey = $this->getBasePath($type) . $dir;
        if (!\Framework\Utils\FileHelper::lock($lockKey, 900)) {
            return ['status' => 'error', 'message' => 'Extension deployment is already in progress'];
        }

        $transaction = null;
        $officialDataBefore = null;
        $officialDataWriteAttempted = false;
        try {
            $action = $this->isLocal($dir, $type) ? 'upgrade' : 'install';
            $transaction = $this->installer->execute($dir, $type, $storeId);

            // 商店部署必须以刚刚验证并切换的新包声明为准检查依赖。
            // 依赖不满足时会进入现有部署回滚边界，不执行生命周期脚本。
            $missing = $this->checkDependencies($dir, $type, $action);
            if ($missing) {
                $list = implode(', ', array_keys($missing));
                throw new \RuntimeException(
                    $this->language->get('extension_dependency_following', ['s' => $list])
                );
            }

            $officialDataBefore = $this->kv->settingGet('officialData') ?? null;
            if ($officialDataBefore === null) {
                $officialDataBefore = [];
            }
            if (!is_array($officialDataBefore)) {
                throw new \RuntimeException('Official store data is malformed');
            }
            $officialData = $officialDataBefore;
            $officialData[$dir] = array_merge($officialData[$dir] ?? [], [
                'storeid' => $storeId,
                'type' => $type === 'theme' ? 2 : 1,
                'version' => (string)$transaction['version'],
                'cloud_version' => (string)$transaction['version'],
                'has_upgrade' => false,
                'last_sync' => time(),
            ]);
            $officialDataWriteAttempted = true;
            if (!$this->kv->settingSet('officialData', $officialData)) {
                throw new \RuntimeException('Failed to persist deployed extension metadata');
            }

            $status = $this->updateStatus($dir, $type, $action);
            if (($status['status'] ?? '') !== 'success') {
                throw new \RuntimeException((string)($status['message'] ?? 'Extension lifecycle update failed'));
            }

            $this->installer->commit($transaction);
            $transaction = null;

            $credentials = $this->market->getCredentials();
            if (!empty($credentials['user_id'])) {
                $this->market->notifyDownloadComplete((int)$credentials['user_id']);
            }

            \Framework\Utils\Runtime::reload();
            return ['status' => 'success', 'dir' => $dir];
        } catch (\Throwable $e) {
            if ($transaction !== null) {
                $rollbackFailures = [];
                if ($officialDataWriteAttempted && $officialDataBefore !== null
                    && !$this->kv->settingSet('officialData', $officialDataBefore)) {
                    $rollbackFailures[] = 'metadata restore failed';
                }
                try {
                    $this->installer->rollback($transaction);
                } catch (\Throwable $rollbackError) {
                    $rollbackFailures[] = $rollbackError->getMessage();
                }
                if ($rollbackFailures) {
                    throw new \RuntimeException(
                        'Extension deployment failed and rollback failed: ' . implode('; ', $rollbackFailures),
                        0,
                        $e
                    );
                }
            }
            return ['status' => 'error', 'message' => $e->getMessage()];
        } finally {
            \Framework\Utils\FileHelper::unlock($lockKey);
        }
    }

    /**
     * 将主题标记为未安装（直接写 config.json，不执行 uninstall 脚本）
     * 同时清理依赖该主题的子主题
     */
    private function markThemeUninstalled(string $dir): void
    {
        $basePath = $this->getBasePath('theme');
        // 自身标记为未安装
        $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';
        if (file_exists($configFile)) {
            $json = (string)file_get_contents($configFile);
            $config = \Framework\Utils\SecurityHelper::jsonDecode($json);
            $config['installed'] = 0;
            $config['enable'] = 0;
            file_put_contents($configFile, \Framework\Utils\SecurityHelper::jsonEncode($config, true));
        }

        // 查找并清理依赖该主题的子主题，记录被清理的目录
        $uninstalledChildren = [$dir];
        $allThemes = $this->getLocalList('theme');
        foreach ($allThemes as $tDir => $tInfo) {
            if ($tDir === $dir) continue;
            $deps = $tInfo['dependencies_theme'] ?? [];
            if (isset($deps[$dir]) || in_array($dir, $deps)) {
                $childFile = $basePath . $tDir . DIRECTORY_SEPARATOR . 'config.json';
                if (file_exists($childFile)) {
                    $json = (string)file_get_contents($childFile);
                    $cfg = \Framework\Utils\SecurityHelper::jsonDecode($json);
                    $cfg['installed'] = 0;
                    $cfg['enable'] = 0;
                    file_put_contents($childFile, \Framework\Utils\SecurityHelper::jsonEncode($cfg, true));
                }
                $uninstalledChildren[] = $tDir;
            }
        }

        // 级联清理：该主题的父依赖若无其他已安装子主题引用，也标记为未安装
        $parentDeps = $allThemes[$dir]['dependencies_theme'] ?? [];
        $queue = [];
        if (is_array($parentDeps)) {
            foreach ($parentDeps as $k => $v) {
                // dependencies_theme 格式: ['parent_dir' => '1.0'] 或 ['parent_dir']
                $queue[] = is_string($k) && !is_numeric($k) ? $k : (is_string($v) ? $v : '');
            }
        }
        $visited = [];
        while (!empty($queue)) {
            $pDir = array_shift($queue);
            if (empty($pDir) || $pDir === $dir || in_array($pDir, $visited)) continue;
            $visited[] = $pDir;
            // 检查是否还有其他已安装主题依赖此父主题（排除本次已卸载的）
            $stillNeeded = false;
            foreach ($allThemes as $tDir2 => $tInfo2) {
                if ($tDir2 === $pDir || in_array($tDir2, $uninstalledChildren)) continue;
                $deps2 = $tInfo2['dependencies_theme'] ?? [];
                $hasInstalled = isset($tInfo2['installed']) && (int)$tInfo2['installed'] === 1;
                if (!$hasInstalled) continue;
                if (isset($deps2[$pDir]) || in_array($pDir, $deps2)) {
                    $stillNeeded = true;
                    break;
                }
            }
            if (!$stillNeeded) {
                $parentFile = $basePath . $pDir . DIRECTORY_SEPARATOR . 'config.json';
                if (file_exists($parentFile)) {
                    $json = (string)file_get_contents($parentFile);
                    $cfg = \Framework\Utils\SecurityHelper::jsonDecode($json);
                    $cfg['installed'] = 0;
                    $cfg['enable'] = 0;
                    file_put_contents($parentFile, \Framework\Utils\SecurityHelper::jsonEncode($cfg, true));
                }
                $uninstalledChildren[] = $pDir;
            }
        }
    }

    private function runActionScript(string $dir, string $type, string $action): void
    {
        $extensionPath = $this->getBasePath($type) . $dir . DIRECTORY_SEPARATOR;
        $configFile = $extensionPath . 'config.json';
        $config = SecurityHelper::jsonDecode((string)file_get_contents($configFile));
        if (isset($config[$action])) {
            $relative = is_array($config[$action]) ? (string)($config[$action]['file'] ?? '') : '';
            if (!$this->isSafeLifecyclePath($relative)) {
                throw new \RuntimeException('Invalid extension lifecycle script path: ' . $action);
            }
        } else {
            // 保留旧扩展按固定文件名声明生命周期脚本的既有协议。
            $relative = $action . '.php';
        }

        $script = $extensionPath . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($script)) {
            if (!isset($config[$action])) {
                return;
            }
            throw new \RuntimeException('Extension lifecycle script is missing: ' . $action);
        }
        include \App\Core\Compile::include($script);
    }

    private function isSafeLifecyclePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '\\') !== false
            || $path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    private function writeConfigAtomically(string $configFile, string $contents): void
    {
        $temporary = $configFile . '.tmp-' . bin2hex(random_bytes(8));
        $written = file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents) || !@rename($temporary, $configFile)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException('Failed to atomically update extension config.json');
        }
    }

    public function checkDependencies(string $dir, string $type, string $action): array
    {
        $currentList = $this->getLocalList($type);
        $ext = $currentList[$dir] ?? null;
        if (!$ext) return [];

        $missing = [];
        if (in_array($action, ['install', 'enable', 'upgrade'])) {
            // 1. 检查通用依赖 (通常指向 plugins)
            $deps = $ext['dependencies'] ?? [];
            $pluginList = ($type === 'plugin') ? $currentList : $this->getLocalList('plugin');

            foreach ($deps as $depDir => $minVer) {
                if ($depDir === 'core') {
                    if (version_compare($this->appConfig['version'] ?? '3.0', (string)$minVer, '<')) {
                        $missing['core'] = $minVer;
                    }
                    continue;
                }

                $depExt = $pluginList[$depDir] ?? null;
                if (!$depExt || !$depExt['installed'] || !$depExt['enable'] || version_compare((string)$depExt['version'], (string)$minVer, '<')) {
                    $missing[$depDir] = $minVer;
                }
            }

            // 2. 检查主题专属依赖
            if ($type === 'theme' && !empty($ext['dependencies_theme'])) {
                $themeList = $currentList;
                foreach ($ext['dependencies_theme'] as $depDir => $minVer) {
                    $depExt = $themeList[$depDir] ?? null;
                    if (!$depExt || !$depExt['installed'] || !$depExt['enable'] || version_compare((string)$depExt['version'], (string)$minVer, '<')) {
                        $missing[$depDir] = $minVer;
                    }
                }
            }
        } else {
            // 卸载/禁用时的反向检查：是否有任何其他插件或主题依赖我？
            $allExtensions = array_merge($this->getLocalList('plugin'), $this->getLocalList('theme'));
            foreach ($allExtensions as $otherDir => $otherExt) {
                if ($otherDir === $dir) continue;
                $allDeps = array_merge($otherExt['dependencies'] ?? [], $otherExt['dependencies_theme'] ?? []);
                // 只要该扩展已安装且声明了依赖，就不允许卸载被依赖者
                if (isset($allDeps[$dir]) && $otherExt['installed']) {
                    $missing[$otherDir] = $otherExt['version'];
                }
            }
        }
        return $missing;
    }

    /**
     * 在分布式锁保护下执行同步逻辑
     * 使用 CacheManager::lock()（Redis SET NX），协程安全
     * 锁获取失败时静默跳过，避免阻塞用户
     */
    private function withSyncLock(string $name, callable $callback): bool
    {
        if (!$this->container) {
            throw new \RuntimeException('Synchronization requires the application container');
        }
        $cache = $this->container->get(\Framework\Cache\Interfaces\CacheInterface::class);
        $lockKey = 'lock:sync:' . $name . ':' . md5($this->appConfig['path'] ?? '');
        // 锁 TTL 60 秒，匹配 MarketClient 超时（30s）+ 网络抖动 + 分页查询耗时
        $token = $cache->lock($lockKey, 60);
        if (!$token) {
            return false;
        }
        try {
            $callback();
            return true;
        } finally {
            $cache->unlock($lockKey, $token);
        }
    }

    public function syncMarketData(array $dirs, bool $force = false): void
    {
        if (empty($dirs)) return;
        if (!$this->market->isLogged()) {
            if ($force) {
                throw new \RuntimeException('Store credentials are required for forced synchronization');
            }
            return;
        }

        // force=true 时（点击"重新获取数据"）增加 60 秒防抖
        if ($force) {
            $lastForceSync = (int)($this->kv->settingGet('storeForceSyncLastTime') ?? 0);
            if ((time() - $lastForceSync) < 60) {
                return;
            }
        }

        $completed = $this->withSyncLock('market', function () use ($dirs, $force) {
            // 双重检查
            if ($force) {
                $lastForceSync = (int)($this->kv->settingGet('storeForceSyncLastTime') ?? 0);
                if ((time() - $lastForceSync) < 60) {
                    return;
                }
            }

            $officialData = $this->kv->settingGet('officialData') ?? [];
            $now = time();
            $toSync = [];

            foreach ($dirs as $dir) {
                $lastSync = $officialData[$dir]['last_sync'] ?? 0;
                if ($force || ($now - $lastSync) > self::SYNC_TTL) {
                    $toSync[] = $dir;
                }
            }

            if (!empty($toSync)) {
                // 分批查询，每批不超过服务端 50 个上限
                $batchSize = 50;
                $dirBatches = array_chunk($toSync, $batchSize);
                foreach ($dirBatches as $batch) {
                    $queryItems = $this->buildMarketQueryItems($batch, $officialData);
                    $marketData = $this->market->queryExtensions($queryItems);
                    $expected = [];
                    foreach ($queryItems as $queryItem) {
                        $expected[$queryItem['dir']] = $queryItem;
                    }
                    if (count($marketData) !== count($expected)
                        || array_diff_key($expected, $marketData)
                        || array_diff_key($marketData, $expected)) {
                        throw new \RuntimeException('Store query response does not match the requested extension set');
                    }

                    foreach ($expected as $dir => $queryItem) {
                        $data = $marketData[$dir];
                        $expectedType = $queryItem['type'] === 'theme' ? 2 : 1;
                        if (!is_array($data)
                            || !hash_equals($dir, (string)($data['dir'] ?? ''))
                            || (int)($data['type'] ?? 0) !== $expectedType) {
                            throw new \RuntimeException('Store query response contains an invalid extension identity');
                        }

                        // 服务端未找到该扩展（已下架/删除），从本地缓存中移除
                        if (empty($data['name'])) {
                            unset($officialData[$dir]);
                            continue;
                        }
                        $data['last_sync'] = $now;
                        // 购买态字段由当前用户查询结果完整覆盖，禁止沿用旧用户缓存。
                        if (!array_key_exists('payment_id', $data)
                            || !array_key_exists('has_bought', $data)
                            || !array_key_exists('price', $data)) {
                            throw new \RuntimeException('Store query response is missing purchase-state fields');
                        }
                        $officialData[$dir] = array_merge($officialData[$dir] ?? [], $data);
                    }
                }
            }

            if (!$this->kv->settingSet('officialData', $officialData)) {
                throw new \RuntimeException('Failed to persist official store data');
            }

            if ($force) {
                if (!$this->kv->settingSet('storeForceSyncLastTime', $now)) {
                    throw new \RuntimeException('Failed to persist forced synchronization timestamp');
                }
            }
        });
        if ($force && !$completed) {
            throw new \RuntimeException('Store synchronization is already in progress');
        }
    }

    /**
     * 构建唯一的批量查询协议：目录、扩展类型、本地版本。
     */
    private function buildMarketQueryItems(array $dirs, array $officialData): array
    {
        $items = [];
        foreach ($dirs as $dir) {
            $dir = (string)$dir;
            $config = null;
            $type = '';
            foreach (['plugin' => $this->pluginPath, 'theme' => $this->themePath] as $candidateType => $basePath) {
                $configFile = $basePath . $dir . DIRECTORY_SEPARATOR . 'config.json';
                if (is_file($configFile)) {
                    if ($config !== null) {
                        throw new \RuntimeException('Extension directory is ambiguous across plugin and theme roots: ' . $dir);
                    }
                    $config = SecurityHelper::jsonDecode((string)file_get_contents($configFile));
                    $type = $candidateType;
                }
            }

            if ($config !== null) {
                $version = (string)($config['version'] ?? '');
            } else {
                $remote = $officialData[$dir] ?? [];
                $remoteType = (int)($remote['type'] ?? 0);
                $type = $remoteType === 1 ? 'plugin' : ($remoteType === 2 ? 'theme' : '');
                $version = (string)($remote['version'] ?? '');
            }

            if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dir)
                || !in_array($type, ['plugin', 'theme'], true)
                || $version === '') {
                throw new \RuntimeException('Cannot build a complete market query identity for: ' . $dir);
            }
            $items[] = ['dir' => $dir, 'type' => $type, 'version' => $version];
        }
        return $items;
    }

    /**
     * 全量同步官方商店目录
     * 调用服务端 sync.html 游标分页接口，获取全部上架扩展
     * 300 秒 TTL + 互斥锁 + DCL
     */
    public function syncOfficialCatalog(bool $force = false): void
    {
        if (!$this->market->isLogged()) {
            return;
        }

        // 外层 TTL 检查（避免不必要的锁竞争）
        $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
        $catalogSyncInterval = 300; // 5 分钟
        if (!$force && (time() - $lastCatalogSync) < $catalogSyncInterval) {
            return;
        }

        $completed = $this->withSyncLock('catalog', function () use ($force) {
            // 双重检查锁（DCL）：锁内再检查一次 TTL
            $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
            if (!$force && (time() - $lastCatalogSync) < 300) {
                return;
            }

            $previousData = $this->kv->settingGet('officialData') ?? [];
            $snapshot = [];
            $cursor = null;
            $hasMore = true;
            $maxId = 0;
            $seenCursors = [];

            while ($hasMore) {
                $params = [
                    'cursor' => $cursor,
                    'limit' => 100,
                    'last_sync' => 0,
                    'dir_flag' => 'next',
                ];
                if ($maxId > 0) {
                    $params['max_id'] = $maxId;
                }

                $response = $this->market->request('sync.html', $params);
                $result = SecurityHelper::jsonDecode($response);

                if (empty($result) || ($result['status'] ?? '') !== 'success') {
                    throw new \RuntimeException((string)($result['message'] ?? 'Official catalog snapshot request failed'));
                }
                $responseData = $result['data'] ?? null;
                if (!is_array($responseData) || !is_array($responseData['items'] ?? null)) {
                    throw new \RuntimeException('Official catalog snapshot response is incomplete');
                }
                $responseMaxId = (int)($responseData['max_id'] ?? 0);
                if ($responseMaxId <= 0 || ($maxId > 0 && $responseMaxId !== $maxId)) {
                    throw new \RuntimeException('Official catalog snapshot anchor changed during pagination');
                }
                $maxId = $responseMaxId;

                $items = $responseData['items'];
                foreach ($items as $item) {
                    $dir = (string)($item['dir'] ?? '');
                    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dir)
                        || !in_array((int)($item['type'] ?? 0), [1, 2], true)) {
                        throw new \RuntimeException('Official catalog snapshot contains an invalid extension identity');
                    }

                    $item['last_sync'] = $maxId;
                    $item['has_bought'] = (bool)($previousData[$dir]['has_bought'] ?? false);
                    $item['payment_id'] = (int)($previousData[$dir]['payment_id'] ?? 0);
                    $snapshot[$dir] = $item;
                }

                $hasMore = !empty($responseData['has_more']);
                if ($hasMore) {
                    $nextCursor = (int)($responseData['cursor'] ?? 0);
                    if ($nextCursor <= 0 || isset($seenCursors[$nextCursor])) {
                        throw new \RuntimeException('Official catalog snapshot cursor did not advance');
                    }
                    $seenCursors[$nextCursor] = true;
                    $cursor = $nextCursor;
                }
            }

            if (!$this->kv->settingSet('officialData', $snapshot)) {
                throw new \RuntimeException('Failed to persist official catalog snapshot');
            }
            if (!$this->kv->settingSet('officialCatalogLastSync', $maxId)) {
                throw new \RuntimeException('Failed to persist official catalog timestamp');
            }
        });
        if ($force && !$completed) {
            throw new \RuntimeException('Official catalog synchronization is already in progress');
        }
    }

    /**
     * 增量同步：下架清理 + 重新上架发现
     * 调用服务端 sync.html 增量模式，同时处理：
     * 1. removed_dirs：精准删除已下架扩展
     * 2. items：发现重新上架的扩展（updated_at > lastCatalogSync 且 status=1）
     * 独立 TTL = 60 秒，不依赖 syncOfficialCatalog 和 syncMarketData
     */
    public function syncRemovedDirs(): void
    {
        if (!$this->market->isLogged()) return;

        $lastRemovedSync = (int)($this->kv->settingGet('storeRemovedSyncLastTime') ?? 0);
        if ((time() - $lastRemovedSync) < 60) return;

        $this->withSyncLock('removed', function () {
            $lastRemovedSync = (int)($this->kv->settingGet('storeRemovedSyncLastTime') ?? 0);
            if ((time() - $lastRemovedSync) < 60) return;

            $lastCatalogSync = (int)($this->kv->settingGet('officialCatalogLastSync') ?? 0);
            if ($lastCatalogSync <= 0) return;

            $officialData = $this->kv->settingGet('officialData') ?? [];
            $changed = false;
            $now = time();
            $cursor = null;
            $hasMore = true;
            $maxId = 0;
            $seenCursors = [];
            $removedSeen = [];

            // 增量上架与下架共用服务端时间锚点；items 必须完整翻页后才写入。
            while ($hasMore) {
                $params = [
                    'cursor' => $cursor,
                    'last_sync' => $lastCatalogSync,
                    'limit' => 100,
                    'dir_flag' => 'next',
                ];
                if ($maxId > 0) {
                    $params['max_id'] = $maxId;
                }

                $response = $this->market->request('sync.html', $params);
                $result = SecurityHelper::jsonDecode($response);
                if (empty($result) || ($result['status'] ?? '') !== 'success') {
                    throw new \RuntimeException((string)($result['message'] ?? 'Removed extension synchronization failed'));
                }
                $responseData = $result['data'] ?? null;
                if (!is_array($responseData)
                    || !is_array($responseData['removed_dirs'] ?? null)
                    || !is_array($responseData['items'] ?? null)) {
                    throw new \RuntimeException('Removed extension synchronization response is incomplete');
                }
                $responseMaxId = (int)($responseData['max_id'] ?? 0);
                if ($responseMaxId <= 0 || ($maxId > 0 && $responseMaxId !== $maxId)) {
                    throw new \RuntimeException('Removed extension synchronization anchor changed during pagination');
                }
                $maxId = $responseMaxId;

                foreach ($responseData['removed_dirs'] as $dir) {
                    if (!is_string($dir)
                        || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dir)) {
                        throw new \RuntimeException('Removed extension synchronization contains an invalid directory');
                    }
                    $removedSeen[$dir] = true;
                }

                foreach ($responseData['items'] as $item) {
                    if (!is_array($item)) {
                        throw new \RuntimeException('Removed extension synchronization contains an invalid item');
                    }
                    $dir = (string)($item['dir'] ?? '');
                    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,63}$/', $dir)
                        || !in_array((int)($item['type'] ?? 0), [1, 2], true)) {
                        throw new \RuntimeException('Removed extension synchronization contains an invalid identity');
                    }
                    $item['last_sync'] = $maxId;
                    $officialData[$dir] = array_merge($officialData[$dir] ?? [], $item);
                    $changed = true;
                }

                $hasMore = !empty($responseData['has_more']);
                if ($hasMore) {
                    $nextCursor = (int)($responseData['cursor'] ?? 0);
                    if ($nextCursor <= 0 || isset($seenCursors[$nextCursor])) {
                        throw new \RuntimeException('Removed extension synchronization cursor did not advance');
                    }
                    $seenCursors[$nextCursor] = true;
                    $cursor = $nextCursor;
                }
            }

            foreach (array_keys($removedSeen) as $dir) {
                if (isset($officialData[$dir])) {
                    unset($officialData[$dir]);
                    $changed = true;
                }
            }

            if ($changed) {
                if (!$this->kv->settingSet('officialData', $officialData)) {
                    throw new \RuntimeException('Failed to persist removed extension synchronization');
                }
            }
            if (!$this->kv->settingSet('storeRemovedSyncLastTime', $now)) {
                throw new \RuntimeException('Failed to persist removed extension synchronization timestamp');
            }
        });
    }
}