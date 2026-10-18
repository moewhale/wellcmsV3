# WellCMS 3.0 中间件与 Meta 驱动矩阵

WellCMS 3.0 采用了 **“Resolver 嗅探模式”**。开发者只需在路由元数据中声明需求（单路由第三参数直接传 meta；`Router::group()` 的组属性才使用 `meta` 节点），框架会自动关联并注入对应的中间件。

## 1. 常态路由控制 Meta 矩阵

所有路由示例遵循[核心第24条](../SKILL.md)：路由小驼峰、类名大驼峰，固定路由禁止 `-`、`_`，路由不要求与控制器类名同名，声明绑定实际Controller/Action；meta键和权限字段保留框架合同。

| Meta 键 (Key) | 关联中间件 | 功能描述 |
| :--- | :--- | :--- |
| `requiresAuth` | `AuthMiddleware` | 用户登录态校验。 |
| `requiresAdminSignIn` | `AdminSignInMiddleware` | 后台管理员权限与登录态校验。 |
| `requiresCsrf` | `CsrfMiddleware` | CSRF 防护，支持数组配置 `['enable' => true, 'ttl' => 3600]`。 |
| `requiresUserPerm` | `UserPermMiddleware` | 权限点校验，如 `['role' => ['administer']]`。**替代控制器内 `groupService->access()`。** |
| `api` | (非中间件) | **响应格式标记**。强制返回 JSON 格式响应。 |
| `api_rate_limit` | `ThrottleMiddleware` | **动态限流覆盖**。用于精细化控制接口频率。 |

### `requiresUserPerm` 权限机制详解

`requiresUserPerm` 是 WellCMS 3.0 的 **路由级权限控制** 方案，控制器内禁止重复调用 `groupService->access()`。

**执行链路：**
```
路由 meta → UserPermResolver → UserPermMiddleware → GroupService::access(群组ID, role) → well_group.字段名
```

**权限字段映射：** `role` 数组中的每个值对应 `well_group` 表的 **字段名**。路由 `'role' => ['administer', 'plugin']` 等价于检查 `well_group.administer OR well_group.plugin`（OR 逻辑，任一满足即放行）。

**常用 `role` 值与 `well_group` 字段对照：**

| Route role | well_group 字段 | 说明 |
|:---|:---|:---|
| `administer` | `administer` | 进后台（基础准入） |
| `user` | `user` | 用户管理 |
| `create_user` | `create_user` | 创建用户 |
| `group` / `create_group` / `update_group` / `delete_group` | 对应字段 | 用户组管理 |
| `plugin` | `plugin` | 插件安装/卸载 |
| `theme` | `theme` | 主题安装/卸载 |
| `store` | `store` | 应用商店 |
| `setting` | `setting` | 系统设置 |
| `task` | `task` | 任务管理 |
| `other` | `other` | 其他 |
| `upload` | `upload` | 上传权限 |

**核心规则：**
1. **路由 meta 是权限单点定义**：权限只需在路由 `meta` 声明，框架自动注入 `UserPermMiddleware`，控制器内 **严禁** 重复 `groupService->access()` 判断。
2. **多层 role 为 OR 逻辑**：`'role' => ['administer', 'plugin']` 表示 administer **或** plugin 任一权限即许通过。若需要多字段 AND 逻辑，需自定义 Resolver。
3. **插件路由**同样适用：插件在 `app_Routes_Routes_before.php` 钩子中声明的路由组 `meta` 同样支持 `requiresUserPerm`。

### `requiresCsrf` CSRF 中间件自动校验

**路由声明（必须）**：登录态 POST 添加 `requiresCsrf`；允许访客访问的 POST 改用 `requiresToken`，不得叠加 `requiresCsrf`：
```php
// ✅ POST 路由标准声明
Router::post('/setting/postSave', [SettingController::class, 'postSave'], [
    'requiresCsrf' => ['enable' => true, 'ttl' => 3600]
]);

// ❌ 严禁：POST 路由缺少 requiresCsrf
Router::post('/setting/postSave', [SettingController::class, 'postSave']);
```

**控制器规则**：`_csrf_token` 由 `CsrfMiddleware` 在路由层自动拦截校验，**控制器内严禁调用 `verifyCsrfToken()` 做手动二次验证**。控制器只需在 GET 渲染时将 token 传入模板，模板在表单/AJAX 中携带 `_csrf_token` 字段，中间件自动完成比对。

```php
// ❌ 严禁：控制器内手动验证 CSRF
public function postSave($request): ResponseInterface
{
    $csrfToken = RequestUtils::param('_csrf_token');
    if (!$this->verifyCsrfToken($csrfToken, $user['salt'] ?? '')) {
        return $this->errorMessage('illegal_operation', 1);
    }
    // ...
}

// ✅ 正确：仅传参，中间件自动校验
// 控制器零 CSRF 代码，token 由路由中间件自动校验
// 模板：<input type="hidden" name="_csrf_token" value="<?php echo $csrfToken;?>">
```

**`<form>` 内禁用 `name="action"`**：`name="action"` 会遮蔽 `form.action` 属性，导致 JS 获取 URL 时拿到 `[object HTMLInputElement]`。表单内动作字段统一使用 `_action`。

**CSRF 双重保护态**：
- **FPM 模式**：服务器端 Session 存储 token，表单提交后比对
- **Swoole 模式**：协程隔离的 StatefulTrait 存储，异步上下文中自动注入

**完整安全链路**：
```
路由声明 requiresCsrf → CsrfMiddleware 自动校验 → 控制器零代码
模板携带 _csrf_token → 中间件比对 → 通过则放行 / 失败则拦截
```

### `api_rate_limit` 使用示例

按当前 `app/Routes/Routes.php` 已正常使用的声明格式编写。以下摘自真实 `/api` 分组内的链接预览 GET 路由（不是要求重复注册）：

```php
use App\Controllers\Api\LinkPreviewController;
use Framework\Http\Router\Router;

Router::group(['prefix' => '/api', 'meta' => ['api' => true]], function () {
    Router::get('/linkPreview', [LinkPreviewController::class, 'fetch'], [
        'requiresAuth' => true,
        'api' => true,
        'api_rate_limit' => [
            'enable' => true,
            'session' => ['limit' => 30, 'window' => 60],
        ],
    ]);
});
```

单路由不得将第三参数再包一层 `meta`，不得使用不存在的 `$router` 实例或 `'Controller@method'` 绑定。插件 Hook 内按插件规范使用全限定类名，不重复 `use Router`；POST 按登录/访客合同声明 `requiresCsrf` / `requiresToken`。

## 2. 自定义 Meta Resolver 开发模板

当插件需要根据自定义 Meta 键（如 `requiresVip`）自动挂载中间件时，必须实现 `MetaMiddlewareResolverInterface`。

**Resolver 文件示例** (`plugins/your_plugin/Meta/VipResolver.php`)：

```php
<?php
namespace Plugins\your_plugin\Meta;

use App\Interfaces\MetaMiddlewareResolverInterface;
use Plugins\your_plugin\Middleware\VipMiddleware;
use Framework\Core\Container;
use Framework\Http\Interfaces\MiddlewareInterface;

class VipResolver implements MetaMiddlewareResolverInterface
{
    private $container;
    public function __construct(Container $container) {
        $this->container = $container;
    }
    public function supports(string $key, $value): bool {
        return 'requiresVip' === $key && true === $value;
    }
    public function create(string $key, $value): MiddlewareInterface {
        return new VipMiddleware($this->container);
    }
}
```
**注入方式**：在插件的 `Providers` 钩子中，将该 Resolver 注册到 `MetaRegistry`。

## 3. 参考实现 (Reference Implementation)
*   **Meta 调度中心标准**：[MetaDispatcherMiddleware.php](/app/Middleware/MetaDispatcherMiddleware.php)
*   **权限解析器参考**：[UserPermResolver.php](/app/Meta/Resolver/UserPermResolver.php)
