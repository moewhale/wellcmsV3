# WellCMS 3.0 后台设置与主题规范

## 1. 插件后台设置规范 (Plugin Admin Settings)

实现方式取决于页面的复杂程度：

### 方案 A：插件 setting.php 入口

复用 `PluginController::setting()` 的现有 include 合同；当前在 **static 闭包** 中传入 `$container`、`$request`、`$method`，不会泄漏 `$this`、`$user`、`$action` 等局部上下文。不得照旧示例直接访问 `$this->container`。

`setting.php` 负责校验传入上下文并转交容器中的实际设置控制器，返回 `ResponseInterface`；GET/POST、语言、权限、模板及安全令牌继续使用宿主和实际控制器合同。以 `well_store_server/setting.php` 开头的有效分发代码为例（其返回后的历史代码不是实现示例）：

```php
if (!isset($container, $request, $method)
    || !$container instanceof \Framework\Core\Container
    || !$request instanceof \Framework\Http\Interfaces\ServerRequestInterface) {
    throw new \RuntimeException('Invalid plugin setting execution context');
}
$settingController = $container->get(
    \Plugins\well_store_server\Controllers\Admin\StoreAdminController::class
);
$settingRequest = $request->withAttribute('_route_meta', ['layout' => 'admin_store_setting']);
return strtoupper($method) === 'POST'
    ? $settingController->postSetting($settingRequest)
    : $settingController->setting($settingRequest);
```

### 方案 B：独立控制器和路由

已有独立控制器/路由的插件继续复用现有入口。按页面复杂度和真实复用需求选择，不用“10 个页面”阈值强制重构；论坛当前设置页由独立后台控制器处理。插件控制器仍遵循核心第 22 条。

## 2. 主题 (Theme) 开发准则

*   **继承机制**：主题通过 `config.json` 中的 `dependencies_theme` 声明父子依赖。
*   **寻址优先级**：
    1.  当前激活主题 (`themes/your_theme/htm/`)
    2.  父级主题 (`themes/parent_theme/htm/`)
    3.  系统核心视图目录 (`app/views/htm/`)
*   **扁平化结构**：**模板仅能放在 `/htm/` 根目录下，严禁使用子目录**。文件名必须与主程序严格对应。
*   **资产隔离**：主题 CSS/JS 优先通过 `config.json` 声明。

## 3. 参考实现 (Reference Implementation)

* **宿主设置 include 合同**：[PluginController.php](/app/Controllers/Admin/PluginController.php)
* **插件设置分发入口**：[well_store_server/setting.php](/plugins/well_store_server/setting.php)（仅参考开头实际可达分发代码）
* **论坛独立设置控制器**：[ForumAdminController.php](/plugins/well_forum/Controllers/Admin/ForumAdminController.php)

