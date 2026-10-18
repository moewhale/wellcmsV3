# WellCMS 3.0 插件架构规约 (Plugin Framework Standards)

## 1. 核心配置文件 `config.json`
插件元数据、资产与权限指令的唯一入口。必须 strictly 区分 **“框架指令”** 与 **“业务配置”**。

### 结构标准 (Standard Structure)
```json
{
    "name": "插件名称",
    "brief": "介绍",
    "type": "0", // 0=插件, 1=主题
    "version": "1.0.0",
    "software_version": "3.0.0",
    "enable": 0,
    "installed": 0,
    "rank": 90,
    "assets": {
        "global": {
            "css": ["css/common.css", "https://cdn.example.com/a.css"],
            "js": ["js/core.js"]
        },
        "admin": {
            "css": ["css/admin.css"],
            "js": ["js/admin.js"]
        }
    },
    "config": {
        "enable_feature_x": true
    },
    "hooks_rank": [],
    "overwrites_rank": []
}
```

## 2. 插件 Rank 排序与劫持 (Ranking)
*   **执行顺序**：`Rank` 较大的插件代码块排列在较小者 **之前** (大者优先)。
*   **Overwrite 决胜**：当多个插件覆盖同一源文件，仅采纳 `Rank` 最高者的物理文件。
*   **原则**：基础库/公用组件设小 Rank (10~50)，业务功能设大 Rank (100+)。

## 3. 前端资产聚合 (Assets Aggregator)

* **单页面**：专用代码可以直接写在对应 HTM 底部 `<script>`，不为此新建 JS 文件；已有非共用 JS 文件仍在页面底部直接引用，不强制迁移工作代码。页面底部允许输出 PHP 但不建议，独立 JS 文件严格禁止 PHP。
* **两个及以上页面共用**：写成 JS 文件，复用插件自己的 `config.json` → `assets` → 编译 → `<asset-js group="..." />` 链路。插件文件保留在插件目录，不放入主程序 core。
* **`@core` 的范围**：引用主程序已有公用模块时，在 `assets` 中写 `@core/文件名.js`。当前 `Compile::compileAssets()` 用文件名解析 `app/views/js/core/`，它不是任意插件路径别名、URL 或 HTML 内联事件语法；不能为迎合示例修改编译器或迁移插件业务代码。
* **编辑器与加载顺序**：`app/views/js/rte.js` 不在 `core/` 下，按用户既定方案直接引用现有文件，禁止改成 `@core/rte.js` 或复制源码。保留静态版本，先加载依赖再执行页面代码。完整业务 URL 由 PHP 生成。
* **编译宏**：CSS 使用 `<asset-css group="global" />`；JS 使用 `<asset-js group="分组名" />`。本地资产按现有内容哈希生成编译产物，外部链接沿现有 external 处理。

以下为现有文件的声明形式示例，分组名称、页面引用和复用范围须按真实需求确定，不要求修改当前配置：

```json
{
    "assets": {
        "thread_detail": {
            "js": ["static/js/thread_detail.js", "@core/WellCMSImageViewer.js"]
        }
    }
}
```

这是“插件文件 + 主程序已有公用模块”的两种来源；是否拆出页面代码由真实使用页面数及用户授权决定，不能把已有资产声明反推为全部模板必须迁移。

## 4. 无侵入注入 (Non-intrusive Injection)
*   **容器注册注入**：模型使用 `app_Providers_ModelServiceProvider_register_models.php`，模块服务使用 `app_Providers_ModelServiceProvider_register_services.php`。
    *   保留 **“类名数组 array_merge + 宿主 foreach”** 模式；无启动副作用的模型加入 `$deferredModels` 标记，在 FPM 按需创建真实单例。
    *   服务需区分原有通用代理与真实类型工厂；注册、启动与请求上下文约束见 [Service_Loading_Performance.md](./Service_Loading_Performance.md)。
*   **中间件注入**：在 `app_Bootstrap_middleware_before.php` 钩子向 `$middlewareQueue` 注入。

## 5. 插件私有 Vendor 处理
*   **存放路径**：物理存放于 `plugins/your_plugin/vendor/`。
*   **加载逻辑**：在插件钩子中执行 `require_once __DIR__ . '/../vendor/autoload.php';`。
*   **原则**：严禁修改主程序 `composer.json`。

## 6. 路由注入规范 (Routing)
*   **命名统一**：遵循[核心第24条](../SKILL.md)：路由小驼峰、类名大驼峰，固定路由禁止 `-`、`_`；路由不要求与控制器类名同名，实际Controller/Action由路由声明绑定。
*   **文件位置**：选择主程序实际存在且满足注册顺序的路由 Hook。当前论坛使用 `Hooks/app_Routes_Routes_before.php`；不能为统一示例擅自改换 Hook 阶段。
*   **命名引用**：必须使用全名限定引用 (Fully Qualified Name)。
    *   `collection/postCreate` → `[\Plugins\your_plugin\Controllers\Frontend\CollectionController::class, 'postCreate']`
*   **Router 类**：严禁在钩子内再次 `use Router`。

## 7. 参考实现 (Reference Implementation)
*   **标准插件配置**：[config.json](/plugins/well_forum/config.json)
*   **路由注入钩子**：[app_Routes_Routes_before.php](/plugins/well_forum/Hooks/app_Routes_Routes_before.php)
