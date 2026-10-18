# WellCMS 3.0 系统架构与开发模式 (Architecture Patterns)

## 1. 控制器模式与数据流闭环 (Controller & Data Flow)
*   **命名与路由绑定**：自有类名大驼峰，路由及Action名小驼峰，固定路由禁止 `-`、`_`；路由不要求与控制器类名同名，路由声明绑定实际Controller/Action，按[核心第24条](../SKILL.md)同步完整调用链。
*   **执行链**：`Router -> Middleware -> Controller -> Service -> Model -> Database`。
*   **业务下沉**：控制器仅负责参数校验与响应分发，业务逻辑必须下沉到 **Service 层**。
*   **数据映射**：Model 仅负责单表 CRUD。**严禁在 Service 或 Model 编写跨表 JOIN**。必须在 PHP 逻辑层进行单表结果合并。
*   **控制器继承与 Action 签名绝对标准**：插件控制器必须直接 `extends BaseController`，严禁建立插件基础控制器继承层。所有 public Action 必须声明为 `public function actionName(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface`。严禁省略 `$request` 参数或返回类型。后台控制器 `use AdminTrait;`，前台控制器 `use FrontendTrait;`，API 控制器不使用界面 Trait。
*   **模板名路由驱动**：控制器中**严禁硬编码模板文件名**。模板名必须从路由元数据读取：`$layout = $request->getAttributes()['_route_meta']['layout'] ?? '默认模板名';`，再传入 `$this->render($layout, $data, '插件目录名')`。`render()` 第三个参数传**插件目录名字符串**（如 `'well_forum'`），严禁传 `true` 或第四个 `$id` 参数。
*   **成功/失败响应绝对委托**：插件 Action 必须直接调用主程序 `BaseController::successMessage()` / `errorMessage()`；严禁直接调用 `$this->responseFormatter->jsonResponseFormat()`，严禁定义 `jsonResponse()`、`apiResponse()`、`success()` 等响应包装方法，严禁通过插件 Trait 或插件基础控制器封装响应。
*   **论坛实现优先标准**：插件控制器的继承、框架 Trait、请求读取、成功/失败响应、页面数据数组及 Action 末尾 `_route_meta` + `render()` 格式，必须以当前 `plugins/well_forum/Controllers/Frontend/CollectionController.php`（前台）和 `plugins/well_forum/Controllers/Admin/CategoryAdminController.php`（后台）为准。

## 1.1 控制器 render() 数据合约 (Controller Data Contract)

后台/前台控制器按实际模板和路由合同构造 `$data`。以下展示登录态表单/列表页面；纯展示页不强制增加表单、分页或令牌字段，访客表单使用宿主 `_token` 合同，不能访问不存在的用户 salt。

### 后台控制器（`use AdminTrait`）

参考实现：`plugins/well_forum/Controllers/Admin/CategoryAdminController.php`

```php
public function index(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute('user', []);  // 当前用户
    $extra = [];

    $csrfToken = $this->getCsrfToken($user['salt']);  // CSRF 令牌
    $menu = $this->getAdminMenu();                     // 侧边栏菜单

    // ... 业务逻辑获取数据 ...

    $pageLinkString = 'admin/example/index';                 // 当前路由名
    $data = [
        // 1. 页面元数据（必须）
        'header' => [
            'title'       => $this->language->get('page_title_key'),
            'keywords'    => $this->language->get('page_title_key'),
            'description' => $this->language->get('page_title_key'),
        ],

        // 2. 导航与菜单（必须）
        'menu'      => $menu,
        'menu_fixed' => ['parent' => 'menu_parent_key', 'child' => 'menu_child_key'],

        // 3. 安全令牌（必须，表单用）
        'csrf_token' => $csrfToken,

        // 4. 分页链接（必须）
        'extra'           => $extra,
        'page_link'       => $this->urlGenerator->url($pageLinkString, $extra),
        'page_link_string' => $pageLinkString,

        // 5. 表单 action（POST 表单页面必须）
        'action' => $this->urlGenerator->url('admin/example/postAction'),

        // 6. 面包屑（二级以上页面推荐）
        'breadcrumb' => [
            'home'  => ['name' => $this->language->get('home_page'), 'url' => $this->urlGenerator->url('admin/panel')],
            'list'  => ['name' => $this->language->get('parent_page'), 'url' => $this->urlGenerator->url('admin/example/list')],
            'title' => ['name' => $this->language->get('current_page'), 'url' => $this->urlGenerator->url($pageLinkString, $extra)],
        ],

        // 7. 模板中使用的语言键（HTML 输出使用 $view->e('language.xxx')）
        'language' => [
            'key1' => $this->language->get('key1'),
            'key2' => $this->language->get('key2'),
        ],

        // 8. 业务数据
        'items' => $items,
    ];

    // 安全读取路由元数据中的模板名
    $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'default_template'];
    return $this->render($routeMeta['layout'], $data, '插件目录名');
}
```

**字段说明：**

| 字段 | 后台 | 要求 | 说明 |
|------|------|------|------|
| `header` | 必须 | `title`,`keywords`,`description` 三个子键 | HTML `<title>` 与 `<meta>` 标签 |
| `menu` | 必须 | `$this->getAdminMenu()` 返回值 | 侧边栏导航渲染 |
| `menu_fixed` | 必须 | `parent` + `child` | 当前菜单项高亮 |
| `csrf_token` | 登录态 POST 表单需要 | `$this->getCsrfToken($user['salt'])` | 表单 CSRF 防护 |
| `extra` | 分页页面需要 | 初始 `[]` | 分页参数透传 |
| `page_link` | 分页页面需要 | `$this->urlGenerator->url()` | 分页链接 |
| `page_link_string` | 分页页面需要 | 路由名 | 分页路由标识 |
| `breadcrumb` | 推荐 | 导航层级 | 面包屑导航 |
| `action` | POST 页面必须 | `$this->urlGenerator->url()` | 表单提交地址 |
| `language` | 必须 | 视图用到的所有语言键 | 模板中 `$view->e('language.*')` 输出 |

### 前台控制器（`use FrontendTrait`）

参考实现：`plugins/well_forum/Controllers/Frontend/CollectionController.php`

```php
public function action(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute('user', []);
    $extra = [];

    $navigation = $this->getNavigation();  // 顶部导航栏（FrontendTrait 提供）

    // ... 业务逻辑 ...

    $pageLinkString = 'my/example';
    $data = [
        'header' => [
            'title'       => $this->language->get('page_title_key'),
            'keywords'    => $this->language->get('page_title_key'),
            'description' => $this->language->get('page_title_key'),
        ],
        'extra'           => $extra,
        'navigation'      => $navigation,        // 前台顶部导航（FrontendTrait::getNavigation()）
        'menu'            => $this->myMenu($user), // 个人中心侧栏（自定义方法）
        'menu_fixed'      => ['parent' => '...', 'child' => '...'],
        'csrf_token'      => $this->getCsrfToken($user['salt']), // 本例是登录态表单；访客/纯展示页面不照搬
        'page_link'       => $this->urlGenerator->url($pageLinkString),
        'page_link_string' => $pageLinkString,
        'breadcrumb'      => [...],              // 推荐
        'action'          => $this->urlGenerator->url('my/example/postAction'), // POST 页面需要
        'language'        => [
            'key1' => $this->language->get('key1'),
        ],
    ];

    $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'default_template'];
    return $this->render($routeMeta['layout'], $data, 'well_xxx');
}
```

**前后台关键区别：**

| 项目 | 后台 (AdminTrait) | 前台 (FrontendTrait) |
|------|-------------------|---------------------|
| 菜单来源 | `$this->getAdminMenu()` | `$this->getNavigation()` + 自定义 `myMenu()` |
| `render()` 第3参 | 插件目录名字符串 `'well_xxx'` | 插件目录名字符串 `'well_xxx'` |
| `menu_fixed` | 控制侧边栏高亮 | 控制个人中心或主导航高亮 |
| `header` 作用 | `<title>` + meta 标签（均需设置） | 同上 |

## 2. 依赖注入与构造铁律 (DI & Constructor Law)
*   **禁止直连**：控制器、Service 严禁直接 `new` 实例化其他 Service/Model，必须由容器注入。
*   **构造函数复用铁律**：严禁在子控制器中无目的地覆盖 `__construct`。必须优先复用 `BaseController` 中已注入的服务。
    *   若确需定义，必须首先执行 `parent::__construct(...)`。
*   **插件 Service 依赖入口**：新复合服务沿用 `Container $container` 入口；只在构造时获取所有方法都必需的轻量依赖。仅用于部分业务分支的依赖在执行分支中按需获取，优先复用插件已有 getter/依赖解析方法。
*   **保留真实类型约束**：现有构造参数、返回类型、`instanceof` 合同必须保留。通用 `LazyLoadingProxy` 不满足具体类型；使用返回真实对象的闭包工厂解决，不要删掉类型或无差别恢复全站预加载。
*   **注册与上下文要求**：修改 Provider、启动 Hook、构造依赖或 `captureContext()` 前，读取 [Service_Loading_Performance.md](./Service_Loading_Performance.md)，按其中的生命周期和验证要求执行。

### 2.1 容器自动装配支持继承构造函数
容器的反射解析（`ReflectionClass::getConstructor()`）**会自动读取继承的构造函数**。因此：
*   Model 继承 `BaseModel` 后，**无需**在子类中重复声明 `__construct(DatabaseInterface $db)`。
*   `ModelServiceProvider` 对 `$deferredModels` 中的模型通过闭包调用 `make()`，按需创建真实单例；Swoole 保留启动预热。反射及预编译定义均会解析 `BaseModel::__construct(DatabaseInterface $db)`。未标记模型保留原注册行为。
*   **禁止**在仅继承 BaseModel 的标准 Model 中冗余定义构造函数，保持类体仅含 `protected $table` 和必要的 `find()` / `maxid()` 默认值覆盖。

## 3. 分布式原子锁规约 (Distributed Locking)
当操作敏感金额、配额或热点缓存重建时，必须在 Service 层引入原子锁。

*   **调用规范**：
    1.  **范围最小化**：仅锁定变更的核心临界区。
    2.  **超时释放**：必须设置合理的 TTL 预防死锁。
    3.  **标准 API**：使用 `CacheInterface::lock(key, ttl)` 或框架封装的 `cacheWithLock()`。

## 4. 物理命名隔离 (Naming Isolation)
*   **表名规范**：插件表必须带前缀 `well_插件名_业务名`。
*   **冲突规避**：严禁占用 `well_core_` 或系统核心表名空间。
*   **字段隔离**：向主程序表添加字段时，建议带插件缩写（如 `plugin_xxx`）。

## 5. 参考实现 (Reference Implementation)
*   **基础控制器 (BaseController) 标准**：[BaseController.php](/app/Controllers/Base/BaseController.php)
*   **容器注册标准驱动**：[ModelServiceProvider.php](/app/Providers/ModelServiceProvider.php)
*   **Service 构造函数规范示例**：[ForumAccessService.php](/plugins/well_forum/Services/ForumAccessService.php) —— 只接受 `Container $container`，内部 `$container->get()` 获取依赖。
*   **后台控制器实现标准**：[CategoryAdminController.php](/plugins/well_forum/Controllers/Admin/CategoryAdminController.php) —— AdminTrait + render() 完整数据合约。
*   **前台控制器实现标准**：[CollectionController.php](/plugins/well_forum/Controllers/Frontend/CollectionController.php) —— 直接继承 BaseController + FrontendTrait + successMessage/errorMessage + 路由元数据 render()。
