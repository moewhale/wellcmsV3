# 服务注册、启动钩子与请求上下文性能约束

适用：新增或修改 Provider、模型/服务注册 Hook、启动钩子、全站中间件、请求上下文注入，以及这些入口调用的构造函数。保持 PHP 7.2 语法和 Swoole 请求隔离；不能用降低权限检查、减少必要业务输出或跳过源码新鲜度校验换取性能。

## 1. 注册与实例化分离

- 以类名数组及 `foreach` 注册。`::class` 本身不加载目标类；注册阶段不要为了验证类名调用会自动加载的 `class_exists()`、反射或 `get()`。
- 区分三个阶段：`register()` 登记绑定、`preResolve()` 预解析非延迟绑定、业务首次 `get()`。`boot()` 中调用 `get()` 同样可能拉起整条依赖链。不能只统计容器方法自身耗时。
- 当前容器 `bind($id, $class, true, true)` 返回通用 `LazyLoadingProxy`；首次 `get()` 仍可能加载目标类。它不满足具体类构造参数、返回值类型或 `instanceof`。已有代理注册不可为统一风格批量替换。
- 要兼顾真实类型和按需创建，使用返回 `$container->make($class, $params)` 的闭包工厂。保留原单例/多例标志及调用参数；不要删掉类型约束来迁就代理，也不要因类型报错就把所有服务恢复为每请求预加载。
- `singleton=false, defer=false` 会在 `preResolve()` 中创建随后丢弃的实例。除非有已验证的启动副作用需求，多例服务应在真实消费者请求时创建。
- `Container::has()` 包含自动装配探测，不能用它判断某功能是否已启用、某实例是否已经存在。功能门禁使用已有配置/路由语义，诊断已创建对象可用 `getInstances()`。

当前模型 Hook 模式：

```php
<?php exit;
$featureModels = [
    \Plugins\your_plugin\Models\ExampleModel::class,
];
$models = array_merge($models, $featureModels);
$deferredModels += array_fill_keys($featureModels, true);
```

由宿主 `ModelServiceProvider` 统一注册真实单例；保留 `$models` 完整列表供其他 Hook 检查或筛选。新模型加入此标记前须确认构造函数仅保存数据库依赖/元数据；不能延迟必须在启动执行的副作用。不要重写仅用于调用父类的构造函数。

需要真实类型的服务组，在合适的 Provider/注册 Hook 中采用：

```php
$realServices = [ExampleService::class => true]; // 值为原 singleton 规则
$defer = !defined('SWOOLE_MODE') || !\SWOOLE_MODE;
foreach ($realServices as $service => $singleton) {
    $container->bind($service, static function ($container, array $params) use ($service) {
        return $container->make($service, $params);
    }, $singleton, $defer);
}
```

这不是要求所有服务预热。上例用于原本启动预热的单例在 FPM 下改为按需创建，同时保留 Swoole 时序。原本延迟的服务、多例和请求服务应保留各自生命周期；同一服务不可同时进入旧代理列表和真实工厂列表。

## 2. 启动 Hook 与构造函数

- 全站注册/启动入口只做必要的绑定、轻量元数据和回调登记。统计回调在执行时获取统计服务；路由、权限解析器不能在登记阶段查询业务数据。
- 先确认调用链，再延迟依赖。记录依赖是否用于当前分支、缓存命中分支是否仍需要它、构造函数是否读文件/建目录/联网/登记定时器。
- 命中缓存的原子读取服务也不要仅因构造函数预取 Model。确认缓存回源、事务内读取、CRUD 和写后失效仍通过原有 Model；复用已有依赖 resolver，保留真实类型和绑定身份。不能跳过事务内缓存旁路或 afterCommit。可参考 `ArticleService` / `ArticleCategoryService` 和论坛 `ForumCategoryService` / `ForumAccessService` / `ForumTempAccessService` / `ForumReplyContentService` / `ForumReplySummaryService`，行为契约为 `DetailServiceLoadingContractTest.php`。缓存权限拒绝、全局权限继承、临时授信过期/次数耗尽，以及写入后的缓存失效都必须保留。
- 复合服务不要为只读路径预取审核、写入、同步、上传等整组服务。将只在特定方法使用的依赖移到该方法或已有的依赖 getter；优先复用插件已有实现，不新增第二套容器/代理。
- 控制器中仅登录用户使用的关注、点赞、收藏等服务，应在已存在的登录门禁内获取；不要为了访客默认展示状态提前创建服务。保留登录用户、作者本人、分页和 Hook 使用的局部变量与行为。
- 可复用容器原服务引用；不要因此缓存当前用户、语言、请求或业务查询结果。解析失败不得留下假的已初始化标记，后续请求应可重试。
- 构造时必须冻结/校验的注册描述符及原配置读取保持原时序；元数据只读方法不能为了返回注册清单拉起设置、索引后端、写入或调度服务。搜索 SourceAdapter 与宿主 API 不兼容时，应明确报告，不用猜测方法、别名或假能力绕过契约。
- 原有可选调度器的 `class_exists()` / `get()` 可移入实际任务分支，保留任务登记、缺失行为、dedupe 和异常重试时序；不能缓存“解析失败/服务缺失”为永久初始化成功。延迟 getter 保留容器原引用和既有代理合同，不能随意增加具体类返回类型。
- 依赖解析可能让出协程时，返回前再次保留已建立的字段引用，例如 `$resolved = $this->container->get(Service::class); return $this->service = $this->service ?? $resolved;`。该规则保持引用身份，不保证容器只构造一次；并发初始化副作用须在容器层保留原有保证，不能用 getter 掩盖。
- 保留配置校验、任务登记、事件监听、全局封禁/权限检查和必要的初始化顺序。调度器专用 Hook 已受 `SCHEDULER_MODE` 控制的，不能把它当作普通 HTTP 开销而删除。
- 仅实例化当前运行态使用的连接池；另一种池保持可按需解析。禁用连接池时，日志注入也不得自动装配该池。

## 3. 请求上下文与 Swoole

- 不要为了 `captureContext()` 在全站中间件中创建当前请求不会使用的重型服务。
- 删除捕获调用前必须追踪所有依赖字段和调用方：显式上下文覆盖、语言、用户、IP、上传命名空间、Session、CLI 和任务入口。
- 多例服务的上下文必须绑定到实际返回给消费者的实例；不得捕获在随后丢弃的实例上。使用现有工厂和 `ServerRequestInterface` / `RequestStack`，并处理 CLI 无请求情况。
- 共享服务的动态状态使用现有 `StatefulTrait` 或在执行时读取当前 PSR 请求；不得保存首次请求到普通单例属性。保留显式 `captureContext()` 的覆盖语义。
- 原有 Swoole 启动预热不能未经测试变成并发首次解析。涉及首次初始化可能让出协程的依赖，应保留预热或验证初始化竞争；不能只用 FPM 测试证明安全。

## 4. 验收与证据

1. 未使用的类不加载、不执行构造函数副作用；首次使用仍返回真实类型，参数传递、单例/多例身份及类型化依赖正确。
2. 验证源码反射和预编译构造定义两条路径。修改跨请求服务时验证 Swoole 并发、不同请求的 Session/语言/IP 隔离。
3. 执行受影响业务的测试，以及有代表性的真实页面 GET；检查状态码、完整 HTML、PHP 错误和核心页面标记。未启用插件不为测试擅自启用，未实测的写操作或 PHP 7.2 运行时不得宣称通过。
4. 先比较确定性指标（加载文件数、已实例化服务数、未使用构造副作用），再报告耗时。分支优化可在隔离进程比较原始/最终源码的实际输出与直接容器获取次数，明确是否包含协作者内部依赖，避免为 A/B 交替改写部署源码。区分页脚时间、完整 HTTP、`render()` 和嵌套计时，不能相加重叠范围；单次快请求不能证明稳定提速。
5. 临时探针需显式开关，不记录密码、Cookie、数据库配置或完整响应；完成后移除并再次访问验证。遵守用户给定的删除范围。

可参考现有契约：`tests/Runtime/PluginModelLoadingContractTest.php`、`HookServiceLoadingContractTest.php`、`ManagementServiceLoadingContractTest.php`、`RequestLifecycleContractTest.php`，以及 `tests/Database/DatabaseProviderRuntimeSelectionContractTest.php`。按改动选择必要测试，不为无关改动机械运行全库。其他插件的读写分支、权限屏障、包完整性与协程首次依赖获取可参考 `tests/Runtime/OptionalPluginServiceLoadingContractTest.php`。
