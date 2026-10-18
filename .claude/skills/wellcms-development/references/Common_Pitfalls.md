# 常见陷阱与教训 (Common Pitfalls & Lessons)

**文档位置**: `.agents/skills/wellcms-development/references/Common_Pitfalls.md`  
**最后更新**: 2026-10-05

---

## 教训 #1: URL 路由匹配必须理解 `processRouteParams` 处理机制

**背景**: WellCMS 3.0 的路由匹配分为两个阶段：
1. `RequestProcessorMiddleware::processRouteParams()` - 处理 URL 路径（包括去掉 `.html` 后缀）
2. `CompiledRouter::match()` - 使用处理后的路径进行路由匹配

**错误模式** (严禁):
```php
// ❌ 错误：认为服务端路由有 .html 后缀
Router::post('/list.html', ...);

// ❌ 错误：认为需要加 plugin/ 前缀
$this->market->request('plugin/list.html', ...);

// ❌ 错误：去掉 .html 后缀
$this->market->request('list', ...);
```

**正确模式** (必须):
```php
// ✅ 服务端路由定义（无 .html 后缀）
Router::post('/list', ...);

// ✅ 客户端请求（带 .html 后缀，MarketClient 会自动拼接）
$this->market->request('list.html', ...);
// 实际拼接过程：
// - MarketClient::apiBasePath = 'api/v1/store'
// - request() 方法：$fullPath = $this->apiBasePath . '/' . ltrim('list.html', '/')
// - 最终结果：'api/v1/store/list.html'

// ✅ 实际匹配流程：
// 1. 客户端调用 $this->market->request('list.html')
// 2. MarketClient 拼接路径：'api/v1/store' + '/' + 'list.html' = 'api/v1/store/list.html'
// 3. 请求 URL：https://store.wellcms.cn/api/v1/store/list.html
// 4. 服务端 processRouteParams(mode=2) 去掉 .html → api/v1/store/list
// 5. CompiledRouter::match() 成功匹配 Router::post('/list')
```

**关键认知**:
- 服务端路由定义 **永远不带** `.html` 后缀
- 客户端请求 **必须带** `.html` 后缀（符合 WellCMS URL 重写模式）
- `processRouteParams` 的 mode 2 (`/user/home/1.html`) 会自动去掉 `.html`
- 客户端 `MarketClient` 的 `apiBasePath = 'api/v1/store'`，请求路径是相对此路径的

**检查清单**:
- [ ] 服务端路由定义无 `.html` 后缀
- [ ] 客户端请求有 `.html` 后缀
- [ ] 客户端请求无额外前缀（如 `plugin/`）
- [ ] 路由匹配失败时，检查 `processRouteParams` 处理后的路径

---

## 教训 #2: 插件视图目录必须是 `views/htm/`，严禁使用 `view/`

**背景**: `TemplateManager::resolvePhysicalPath()` 第 104 行对插件模板的硬编码查找路径为：
```php
$path = $this->pluginsPath . $templateDir . '/views/htm/' . $fileName . '.htm';
```

**错误模式** (严禁):
```
// ❌ 错误：使用单数 view/ 目录
plugins/well_store_server/view/user_sites.htm

// ❌ 错误：模板放到 views/ 根目录而非 views/htm/
plugins/well_store_server/views/user_sites.htm
```

**正确模式** (必须):
```
// ✅ 插件模板必须放在 views/htm/ 下
plugins/well_store_server/views/htm/user_sites.htm
```

**关键认知**:
- 主程序视图目录是 `app/views/htm/`，插件必须遵循同样的 `views/htm/` 结构
- `render('user_sites', ..., 'well_store_server')` 会拼接为 `well_store_server/views/htm/user_sites.htm`
- 任何偏离都会导致模板解析失败（返回 404 或空白页）

**检查清单**:
- [ ] 插件模板放在 `plugins/{plugin_name}/views/htm/` 下
- [ ] 不使用单数 `view/` 目录
- [ ] 不在 `views/` 根目录直接存放 `.htm` 文件

---

## 教训 #3: 插件 Hook 文件头必须是 `<?php exit;`

**背景**: WellCMS 3.0 的 Hook 机制在编译阶段将 `Hooks/` 目录下的文件内容物理注入到主程序对应位置。`<?php exit;` 不是运行时代码，而是**编译期标记**——框架在扫描 Hook 点注释（如 `// hook app_Routes_Routes_end.php`）后，提取 Hook 文件中 `exit;` 之后的物理文本进行烧录。如果缺少此行，Hook 文件被直接 `include` 时会导致 PHP 进程异常终止。

**错误模式** (严禁):
```php
// ❌ 错误：缺少 exit; 或认为它是运行时代码而删除
<?php
Router::get('/sites', ...);

// ❌ 错误：在 exit 之后继续写可执行逻辑（exit 之后才是要烧录的内容）
<?php exit;
return []; // 这行会被烧录到主程序，可能引发语法错误
```

**正确模式** (必须):
```php
// ✅ Hook 文件标准头
<?php exit;

// --- 以下所有内容在编译阶段烧录到主程序 ---
Router::get('/sites', ...);
```

**关键认知**:
- 所有 `plugins/{plugin}/Hooks/*.php` 文件都必须以 `<?php exit;` 开头
- `exit;` 之后的代码不会被 PHP 运行时执行，而是由框架编译器提取并压平到目标位置
- 修改 Hook 文件后必须触发重扫（删除 `compile_manifest.php` 或切换 DEBUG 模式）

**检查清单**:
- [ ] 每个 Hook 文件第一行是 `<?php exit;`
- [ ] `exit;` 之后才是实际要注入的代码/文本
- [ ] 修改 Hook 后清理编译缓存

---

## 教训 #4: `update()` 增量/减量语法必须使用键名后缀（`downloads+`），严禁嵌套数组

**背景**: WellCMS Query Builder 的 `compileUpdate` 遍历 `$update` 数组的**键名**来判断是否为增量/减量操作。它检查键名的最后一个字符是否为 `+` 或 `-`，不支持 Laravel/Eloquent 风格的 `['field' => ['+=' => 1]]` 嵌套数组语法。

**错误模式** (严禁):
```php
// ❌ 错误：嵌套数组语法不被 Query Builder 识别
$this->pluginService->update(['id' => $id], ['downloads' => ['+=' => 1]]);

// ❌ 错误：paramsValueEscape 会把数组强制转成字符串 "Array"
// 生成 SQL: UPDATE store_server_plugin SET `downloads` = 'Array' WHERE `id` = ?
// 在 MySQL strict mode 下，INT 字段写入 'Array' 字符串，PDO 直接抛异常
```

**正确模式** (必须):
```php
// ✅ 增量：键名以 '+' 结尾
$this->pluginService->update(['id' => $id], ['downloads+' => 1]);
// 生成 SQL: UPDATE store_server_plugin SET `downloads` = `downloads` + ? WHERE `id` = ?

// ✅ 减量：键名以 '-' 结尾
$this->pluginService->update(['id' => $id], ['stock-' => 1]);
// 生成 SQL: UPDATE store_server_plugin SET `stock` = `stock` - ? WHERE `id` = ?
```

**关键认知**:
- 所有方言编译器（MySQL / PgSQL / SQLite / SqlServer）均遵循相同的键名后缀规则
- `paramsValueEscape()` 只处理 `int` 和 `string`，数组会被强转为 `"Array"` 字符串
- `bulkUpdate` 同理：行数据中使用 `'views+' => 5` 而非嵌套数组
- 此错误在 `download()`、`purchase()` 等高频写操作中被反复踩中，必须形成肌肉记忆

**检查清单**:
- [ ] 所有增量/减量更新使用键名后缀语法（`field+` / `field-`）
- [ ] 绝不使用 `['field' => ['+=' => N]]` 或 `['field' => ['-=' => N]]`
- [ ] 代码审查时重点检查 `update()` 的第二个参数中是否出现嵌套数组

---

*本文档由 AGENT.md 分离而来，用于集中记录开发和维护过程中的常见陷阱。*

---

## 教训 #5: 路由小驼峰、类名大驼峰与全调用链一致

**命名权威**：遵循[核心第24条](../SKILL.md)。路由与控制器类名不要求同名或按名称推导，Controller/Action由路由声明绑定；路由名称及命名参数使用小驼峰，类名使用大驼峰，固定路由禁止 `-`、`_` 和首字母大写。

**错误模式** (严禁):
```php
// ❌ 错误：固定路由主体首字母大写
Router::post('/Collection/postCreate', [CollectionController::class, 'postCreate']);
// ❌ 错误：固定路由动作使用连字符
Router::post('/collection/post-create', [CollectionController::class, 'postCreate']);
// ❌ 错误：固定路由动作使用下划线
Router::get('/collection/add_thread_modal/{collectionId}', [CollectionController::class, 'addThreadModal']);
```

**正确模式** (必须):
```php
// ✅ 路由小驼峰、控制器类名大驼峰，声明绑定实际Action
Router::post('/collection/postCreate', [CollectionController::class, 'postCreate']);
Router::get('/collection/addThreadModal/{collectionId}', [CollectionController::class, 'addThreadModal']);
```

**关键认知**:
- 当前框架/论坛作为注册、权限与URL生成机制的事实源，已有不合规命名不能作为规范示例。
- 修正路由时必须追踪导航、表单、API/JS、Job回调和文档，按授权范围同步完整调用链，保留原功能；禁止另建旧路由别名或兼容入口。
- 插件/Hook/模板名、权限字段、令牌字段及动态内容参数值不是固定路由标识，不改写它们的数据合同。

**检查清单**:
- [ ] 路由名/固定路径段/命名参数小驼峰；固定路由无 `-` 或 `_`。
- [ ] 控制器类名大驼峰；路由声明绑定实际Controller/Action，不按名称推导类名。
- [ ] 路由声明、URL生成器、模板、JS/回调及文档同改同验。
- [ ] 没有迁移、别名或兼容路由。

---

## 教训 #6: `<form>` 内禁止使用 `name="action"`——会遮蔽 `form.action` 属性

**背景**: HTML DOM 规范中，表单元素可通过 `form.fieldName` 直接访问其命名子元素。当 `<input name="action">` 存在时，`form.action` 返回该 input 元素而非表单的 `action` URL 字符串。JavaScript `ajax-form` 处理器读取 `form.action` 时获得 `[object HTMLInputElement]`，导致路由匹配失败。

**错误模式** (严禁):
```html
<!-- ❌ 错误：name="action" 遮蔽了 form.action -->
<form class="ajax-form" action="/admin/MultiSite/PostSetting">
    <input type="hidden" name="action" value="save_node">
</form>
```
JS 取 `form.action` → `[object HTMLInputElement]` → URL 变成 `/admin/MultiSite/[object HTMLInputElement]`

**正确模式** (必须):
```html
<!-- ✅ 控制器生成 admin/multiSite/postSetting 的完整 URL；字段使用 _action -->
<form class="ajax-form" action="<?php echo $view->e('urls.post_setting');?>">
    <input type="hidden" name="_action" value="save_node">
</form>
```
```php
// 控制器同步修改
$action = RequestUtils::param('_action', '');
```

**禁用字段名（会遮蔽原生属性）**:
| 字段名 | 遮蔽属性 | 后果 |
|--------|----------|------|
| `action` | `form.action` | URL 变成 `[object HTMLInputElement]` |
| `method` | `form.method` | GET/POST 判断失效 |
| `enctype` | `form.enctype` | 编码类型错乱 |
| `submit` | `form.submit()` | 提交方法被覆盖 |
| `reset` | `form.reset()` | 重置方法被覆盖 |

**检查清单**:
- [ ] 所有 `<form>` 内无 `name="action"` 的 input
- [ ] 使用 `_action` 作为动作标识字段名
- [ ] 控制器 `RequestUtils::param()` 读取的字段名与模板一致

---

## 教训 #7: CSRF 必须由路由中间件校验，严禁控制器手动验证

**背景**: WellCMS 3.0 的 `CsrfMiddleware` 在路由层拦截登录用户的 `_csrf_token`，`TokenMiddleware` 校验访客 `_token`。控制器内手工校验会造成重复或状态错配。**登录态 POST 声明 `requiresCsrf`；访客 POST 声明 `requiresToken`，控制器零令牌校验代码。**

**错误模式** (严禁):
```php
// ❌ 错误 1：路由缺少 requiresCsrf 声明
Router::post('/setting/postSave', [SettingController::class, 'postSave']);

// ❌ 错误 2：控制器内手动验证 CSRF
public function postSave($request): ResponseInterface
{
    $csrfToken = RequestUtils::param('_csrf_token');
    if (!$this->verifyCsrfToken($csrfToken, $user['salt'] ?? '')) {
        return $this->errorMessage('illegal_operation', 1);
    }
    // ...
}
```

**正确模式** (必须):
```php
// ✅ 路由声明 requiresCsrf
Router::post('/setting/postSave', [SettingController::class, 'postSave'], [
    'requiresCsrf' => ['enable' => true, 'ttl' => 3600]
]);

// ✅ 遵循核心第 22 条声明请求类型；中间件负责 CSRF 校验
public function postSave(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface
{
    $action = RequestUtils::param('_action', '');
    // 直接处理业务逻辑，无需任何 CSRF 验证代码
}
```

**关键认知**:
- 安全链路：登录态路由声明 `requiresCsrf` → `CsrfMiddleware`；访客路由声明 `requiresToken` → `TokenMiddleware`；控制器零代码
- GET 渲染时传 token（`'csrf_token' => $this->getCsrfToken($user['salt'])`），模板携带 `_csrf_token`
- 控制器内 **严禁** 出现 `verifyCsrfToken()` 调用

**检查清单**:
- [ ] 登录态 POST 声明 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]`，访客 POST 声明 `'requiresToken' => ['enable' => true, 'ttl' => 600]`
- [ ] 控制器内无 `verifyCsrfToken()` 调用
- [ ] 控制器内无 `RequestUtils::param('_csrf_token')` 手动读取
- [ ] 模板表单/AJAX 中正确携带 `_csrf_token` 字段

---

## 教训 #8: 模板严禁拼接路由或 URL

**背景**: WellCMS 3.0 的 `url_rewrite_on` 模式（`0/1/2/3`）会改变最终 URL 形态（如 `.html` 后缀、查询串格式）。若在模板（`views/htm/*.htm`）中拼接路由路径或查询字符串，生成的 URL 将与当前重写模式不一致，导致 404、SEO 断裂或转义缺口。**所有 URL 必须由控制器或服务层通过 `$this->urlGenerator->url()` 生成，以完整变量形式传入模板。**

**错误模式** (严禁):
```php
// ❌ 错误 1：在模板中拼接路径段
// manage_article_revisions.htm
<a href="<?php echo htmlspecialchars((string)$compareAction . '/' . (int)$rev['id']);?>">

// ❌ 错误 2：在模板中拼接查询字符串
// article_detail.htm
<a href="<?php echo htmlspecialchars((string)($category['slug'] ?? '') . '?tag=' . urlencode((string)($tag['slug'] ?? '')));?>">
```

**正确模式** (必须):
```php
// ✅ 控制器中生成完整 URL 数组
private function buildRevisionViewUrls(int $articleId, int $createdAt, array $revisions = []): array
{
    $compareUrls = [];
    foreach ($revisions as $rev) {
        $revisionId = (int) ($rev['id'] ?? 0);
        if ($revisionId <= 0) {
            continue;
        }
        $compareUrls[$revisionId] = $this->urlGenerator->url(
            'manage/article/compare/' . $articleId . '/' . $createdAt . '/' . $revisionId
        );
    }

    return [
        'compare_urls' => $compareUrls,
        // ...
    ];
}

// ✅ 模板使用安全输出方法输出控制器生成的完整 URL
<a href="<?php echo $view->e('compare_urls.' . (int)$rev['id']); ?>">对比</a>
```

**关键认知**:
- 模板是“绝对被动”的视图层，只负责渲染已由控制器/Service 准备好的数据
- 查询参数同样必须通过 `$this->urlGenerator->url($route, ['tag' => $tagSlug])` 生成，禁止在模板中手动拼 `?key=value`
- 路径段拼接会破坏铁律 #24（路由小驼峰、类名大驼峰及全调用链对齐）的统一契约

**检查清单**:
- [ ] 模板中无 `$xxx . '/' . $id` 形式的路径拼接
- [ ] 模板中无 `'?tag=' . urlencode(...)` 等查询字符串拼接
- [ ] 所有链接/表单 action/data-href/data-action 均使用控制器传入的完整 URL 变量
- [ ] 列表页、详情页、版本历史、对比页等高频模板重点复查

---

## 教训 #9: 模板输出严禁直接调用 `htmlspecialchars()`

**背景**: WellCMS 3.0 视图层已提供统一的安全输出方法 `$view->e('key')`，内部使用 `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`。若模板中零散调用 `htmlspecialchars()`，会导致转义方式不一致（`ENT_COMPAT` vs `ENT_QUOTES`、编码参数遗漏）、代码冗余，且容易遗漏局部变量抽取路径。**HTML 文本及属性使用 `$view->e()`；`$view->raw()` 只取原始数据用于逻辑。脚本数据序列化遵循核心第 28 条，禁止自行调用底层 HTML 转义函数。**

**错误模式** (严禁):
```php
// ❌ 错误 1：直接调用 htmlspecialchars
<a href="<?php echo htmlspecialchars((string)$compareAction);?>">
<?php echo htmlspecialchars((string)($article['title'] ?? '')); ?>

// ❌ 错误 2：把需要转义的数据抽到本地变量后再手动转义
$article = $view->get('article');
<?php echo htmlspecialchars((string)($article['title'] ?? '')); ?>
```

**正确模式** (必须):
```php
// ✅ 通过视图对象的安全输出方法输出
<?php echo $view->e('article.title'); ?>

// ✅ 原始数据仅用于遍历/判断，不直接输出
<?php $article = $view->raw('article', []); ?>
<?php if (!empty($article['tags'])) { foreach ($article['tags'] as $tagIndex => $tag) { ?>
    <span><?php echo $view->e('article.tags.' . $tagIndex . '.name'); ?></span>
<?php } } ?>

// ✅ 完整 URL 输出到 HTML 属性时同样统一转义
<button data-href="<?php echo $view->e('rollback_action'); ?>">回滚</button>
```

**关键认知**:
- `$view->e()` 自动处理 `ENT_QUOTES` 和 `UTF-8`，统一转义策略
- 需要转义的数据应保留在 `$view` 数据池中，通过键路径访问，而不是抽取到本地变量再手动转义
- 局部变量只用于逻辑判断、遍历或输出已确认安全的值

**检查清单**:
- [ ] 模板中无 `htmlspecialchars(`、`htmlentities(` 调用
- [ ] 输出文本/属性值优先使用 `$view->e('key.subkey')`
- [ ] 遍历或判断使用 `$view->raw('key', $default)`
- [ ] 本地变量仅存放 URL、ID、布尔等无需转义的预封装值

---

## 教训 #10: 区分页面脚本、公用资产和交互 API

遵循核心第 28 条：单页面逻辑可写在 HTM 底部 `<script>` 中，不另建 JS 文件；两个及以上页面公用逻辑写成 JS 文件，使用插件资产编译机制。主程序现有 core 模块使用 `@core` 引用；`rte.js` 直接引用。不能因技能更新迁移已有可用页面。

交互复用 `ajax-get`、`ajax-post`、`ajax-form` 或 `wellcms.*`，禁止另封装 `fetch`、`XMLHttpRequest`、`$.ajax`。优先声明式交互，现有内联事件本身不构成重写授权。

```html
<!-- action 为控制器生成的完整 URL -->
<button id="page-action" class="ajax-post"
        data-href="<?php echo $view->e('action');?>"
        data-confirm="<?php echo $view->e('language.delete_confirm');?>">
    <?php echo $view->e('language.delete');?>
</button>
```

需要页面专用交互时在页面底部使用 `wellcms.*`，动态参数优先由 HTML 属性传入。页面底部脚本允许直接输出 PHP，但不建议；必要时使用 JSON 序列化，不能用 `$view->e()` 拼 JS 字符串。独立 `.js` 文件严格禁止 PHP。

```html
<script>
// 仅用于 HTM 底部脚本；优先改用 data-* 读取。
var pageMessage = <?php echo json_encode($view->raw('language.delete_confirm', ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
</script>
```

检查页面脚本归属、依赖顺序、完整 URL、交互 API 和正确的输出上下文即可；不为规范审计新建框架、迁移页面或重复测试已有功能。

