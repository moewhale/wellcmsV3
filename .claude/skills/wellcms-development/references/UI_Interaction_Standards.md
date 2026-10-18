# WellCMS 3.0 前端美学与交互规约 (UI & Interaction Standards)

> **源码位置**: `app/views/js/main.js` —— 所有前端交互的唯一标准库。
> **严禁**在模板 JS 中引入外部 AJAX 库或自行封装 `fetch/XMLHttpRequest`。

---

## 1. 声明式交互 (Declarative UI)

基于 `GlobalClickHandler` + `GlobalFormHandler` 驱动，通过 HTML 属性控制业务逻辑，减少重复 JS。

请求中的固定路由名遵循[核心第24条](../SKILL.md)：小驼峰、禁止 `-`、`_`，控制器类名大驼峰且不要求与路由同名；PHP侧生成完整URL后交给交互层。`ajax-post` 等宿主交互属性/类名不是路由名，不改动这些既有接口。

### 1.1 点击交互 (GlobalClickHandler)

| 属性 | 说明 |
|------|------|
| `.ajax-get` / `.ajax-post` | 点击触发异步请求 |
| `data-href` / `href` / `data-url` | 目标 URL（优先级：`data-href` > `href` > `data-url`） |
| `data-confirm` | 确认文案，如 `"确定删除吗？"` |
| `data-arg` / `data-json` | POST 提交的 JSON 数据，如 `{"id":1}` |
| `data-reload="false"` | 成功后不自动刷新页面 |
| `data-modal-title` | 触发 `ajaxModal` 弹窗，值为弹窗标题 |
| `data-modal-url` / `href` | `ajaxModal` 加载的远程 URL |
| `data-modal-size` | 弹窗尺寸：`sm` / `md` / `lg` / `xl` / `full` |
| `data-modal-callback` | 弹窗加载完成后执行的回调函数名 |
| `data-modal-arg` | 传入回调函数的参数 |

**示例：** 以下 `urls.*` 均由 PHP 控制器通过 `urlGenerator->url()` 生成完整 URL，不能照示例另造不存在的路由；请求方法及令牌沿用实际路由合同。
```html
<!-- AJAX GET 请求 -->
<a class="ajax-get" data-href="<?php echo $view->e('urls.delete');?>" data-confirm="确定删除？">删除</a>

<!-- AJAX POST 请求 -->
<button class="ajax-post" data-href="<?php echo $view->e('urls.ban');?>" data-arg='{"id":1}'>封禁</button>

<!-- 声明式弹窗（加载远程页面） -->
<a href="<?php echo $view->e('urls.edit');?>" data-modal-title="编辑用户" data-modal-size="lg">编辑</a>
```

### 1.2 表单交互 (GlobalFormHandler)

| 属性 | 说明 |
|------|------|
| `.ajax-form` | 接管表单提交，阻止默认行为，通过 `wellcms.post()` 发送 |
| `data-loading-text` | 提交中按钮文案，如 `"保存中..."` |
| `data-timeout` | 成功后跳转/刷新延迟（秒），默认 1 |
| `data-modal-size` | 错误弹窗尺寸 |

**成功响应处理：**
- `code === 0` 时显示 `toast('success')`，若 `res.data.alert` 为真则显示 `success()` 弹窗
- 若 `res.data.redirect.url` 存在，延迟跳转
- 触发 `CustomEvent('wellcms:form-success', { detail: res })`

**错误响应处理：**
- `res.data.field` 存在时，调用 `showInputError(field, message)`
- 否则显示 `error()` 阻塞弹窗

---

## 2. wellcms JS API 速查手册

全局唯一实例：`window.wellcms`（由 `new WellCMSUI()` 创建）。

**⚠️ 致命陷阱**: `window.wellcms.ui` **不存在**！只有 `window.wellcms`。所有方法直接通过 `wellcms.xxx()` 调用。

### 2.1 弹窗与通知

#### `wellcms.dialog(options)`
通用模态弹窗。

```javascript
wellcms.dialog({
    title: '标题',
    body: '<p>HTML 内容</p>',
    type: 'info',        // 'info' | 'success' | 'error' | 'confirm'
    size: 'md',          // 'sm' | 'md' | 'lg' | 'xl' | 'full'
    confirmText: '确认',
    cancelText: '取消',
    onConfirm: function() { /* 同步回调 */ },
    onCancel: function() { /* 同步回调 */ }
});
```

**🔴 关键限制 —— onConfirm 陷阱：**
- `onConfirm` **不支持 async**（点击后立即 `dialogEl.remove()`，不等待异步完成）
- `onConfirm` **不支持 `return false` 阻止关闭**
- 若需在确认时执行 AJAX 并保留弹窗，**必须在 `body` 内放置自定义按钮**，绕过 `dialog` 的 confirm 逻辑；或使用 `type: 'info'` 不渲染确认按钮

#### `wellcms.alert(message, title, size)` / `wellcms.success(message, title, size)` / `wellcms.error(message, title, size)`
阻塞式模态对话框，内部调用 `dialog()`。

```javascript
wellcms.error('操作失败', '错误', 'sm');
wellcms.success('保存成功', '成功');
```

#### `wellcms.confirm(message, onConfirm, onCancel)`
确认对话框。

```javascript
wellcms.confirm('确定删除吗？', function() {
    // 同步操作
});
```

#### `wellcms.toast(message, type, duration)` ⭐ 标准反馈方式
非阻塞 Toast 提示。**`GlobalFormHandler` 和 `GlobalClickHandler` 内部统一使用此方法。**

```javascript
wellcms.toast('操作成功', 'success', 3000);  // type: success|error|warning|info
wellcms.toast('保存失败', 'error');
```

### 2.2 AJAX 请求

#### `wellcms.get(url, params, retry)`
GET 请求，自动携带 `X-Requested-With: XMLHttpRequest`。

```html
<div id="list-config" data-list-url="<?php echo $view->e('urls.list');?>"></div>
<script>
(async function () {
    const listUrl = document.getElementById('list-config').dataset.listUrl;
    const res = await wellcms.get(listUrl, { page: 1, keyword: 'test' });
    // 只传完整 URL 时省略第二个参数：await wellcms.get(listUrl);
})();
</script>
```

注意：第二个参数是 number 时会被视为 retry 次数，而不是查询参数。

**返回值：**
- JSON 响应自动解析为对象 `{code, data, message}`
- HTML 响应返回 `{code: 0, data: html, isHtml: true, parse(selector)}`
- 网络错误返回 `{code: -1, message: '...'}`

#### `wellcms.post(url, data, onProgress)`
POST 请求，自动将对象转为 `FormData`，自动携带 `X-Requested-With: XMLHttpRequest`。

```html
<button id="request-config"
     data-action="<?php echo $view->e('action');?>"
     data-upload-url="<?php echo $view->e('upload_url');?>"
     data-csrf-token="<?php echo $view->e('csrf_token');?>">
    <?php echo $view->e('language.submit');?>
</button>
<script>
document.getElementById('request-config').addEventListener('click', async function () {
    const config = this.dataset;
    const res = await wellcms.post(config.action, { _csrf_token: config.csrfToken });
    // 已有上传组件如需展示进度，仍使用其 FormData 和上传回执合同；
    // wellcms.post(config.uploadUrl, formData, function (percent) { ... });
});
</script>
```

### 2.3 URL 生成

业务请求地址必须由 PHP 控制器/Service 的 `urlGenerator->url()` 生成完整 URL，通过模板 `data-*` 或 JSON 配置交给交互层。JS 不硬编码路由或拼接路径。

`wellcms.url(route, extra, config)` 是主程序已有 API；维护其已有调用时必须传入服务器实际 `path`、`url_rewrite_on`，不能使用猜测的默认配置。本规范的业务示例统一使用 PHP 完整 URL，不另建 JS 路由生成链路。重写后的连字符是框架编码结果，不改变固定路由名使用小驼峰且禁用 `-`/`_` 的要求。

### 2.4 远程弹窗加载

#### `wellcms.ajaxModal(url, title, options)`
AJAX 加载远程 HTML 到弹窗。

```html
<button id="edit-modal" data-edit-url="<?php echo $view->e('urls.edit');?>"
        data-title="<?php echo $view->e('language.edit');?>">
    <?php echo $view->e('language.edit');?>
</button>
<script>
document.getElementById('edit-modal').addEventListener('click', function () {
    wellcms.ajaxModal(this.dataset.editUrl, this.dataset.title, { size: 'lg' });
});
</script>
```

**工作流程：**
1. 显示 loading 弹窗
2. GET 加载远程 HTML
3. 提取 `.ajax-body` 或 `#body` 的 **innerHTML**（`.ajax-body` 元素本身不在弹窗 DOM 中）
4. 移除 `script, link, style, meta, title, iframe` 标签
5. 提取并执行 `<script ajax-eval="true">...</script>` 脚本
6. 提取并加载外部 `<script src="...">`

**🔴 关键陷阱 —— ajaxModal 与 .ajax-body：**
- `ajaxModal` 注入的是 `.ajax-body` 的 `innerHTML`，**`.ajax-body` 元素本身不在弹窗 DOM 中**
- 如果远程页面的脚本包含 `document.querySelector('.ajax-body')`，会返回 `null` 导致崩溃
- **结论**：依赖 `.ajax-body` 元素自身属性的脚本（如 `ajaxBody.getAttribute('payapi')`）**不兼容 `ajaxModal`**

### 2.5 表单错误提示

#### `wellcms.showInputError(name, message)`
在指定输入框上方显示错误提示（带抖动动画），输入时自动消失。

```javascript
wellcms.showInputError('password', '密码错误');
```

---

## 3. PHP → JS 参数传递规范

### 脚本归属与 PHP 输出边界

只有单页面使用的代码可以直接写在 HTM 底部 `<script>` 中，不另建 JS 文件。两个及以上页面公用代码写成 JS 文件，通过插件自身资产机制编译、加载；主程序已有 core 模块使用 `@core`。`@core` 不等于 HTML `onclick` 等事件属性，详见[插件资产规范](./Plugin_Framework_Standards.md#3-前端资产聚合-assets-aggregator)。`rte.js` 保持直接引用，先加载依赖再执行页面脚本。

页面底部脚本允许输出 PHP，但为避免格式化破坏语法不建议使用；独立 `.js` 文件严格禁止直接输出 PHP。优先通过 HTML `data-*` 或 `application/json` 配置节点传值：

```html
<div id="page-config" data-action="<?php echo $view->e('action');?>"></div>
<script>
(function () {
    var config = document.getElementById('page-config');
    // 在实际交互触发时复用 wellcms.get/post，URL 来自 config.dataset.action。
})();
</script>
```

确需在 HTM 底部脚本输出 PHP 时，用 JSON 编码完整值，避免引号、换行或 `</script>` 破坏脚本；不能把 `$view->e()` 的 HTML 转义当 JS 转义：

```html
<script>
var pageConfig = <?php echo json_encode($view->raw('page_config', []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);?>;
</script>
```

配置节点同样使用上述 JSON 编码，读取时 `JSON.parse(node.textContent)`；不包含不可 JSON 序列化的二进制值。HTML 属性一律 `$view->e()`，PHP 负责生成所有请求的完整 URL。已有单页面 JS 文件仍在页面底部直接引用其现有完整资产 URL，不以本规范为由迁移工作页面。

`data-csrf-token` 对应 `dataset.csrfToken`，`data-url-rewrite-on` 对应 `dataset.urlRewriteOn`；令牌名称须与实际成员/访客路由合同一致。

---

## 4. 后台视觉规范 (Admin Aesthetics)

*   **列表页 (List)**：
    *   `thead` 背景：`bg-gray-50/50` (极浅灰)。
    *   多选主控：ID 必须为 `selectAll`。
    *   删除交互：先核对当前交互层是否实现 `ajax-delete`；不存在或不能满足需求时使用已有 `.ajax-post` / `.ajax-get` 接管，方法、权限和令牌沿实际路由合同。本项目当前 `main.js` 没有 `ajax-delete` 处理器，不得只添加该类后假定可用。
*   **组件化 (Components)**：
    *   **Switch**：严禁原生复选框，必须使用 `well-switch` 控件。
    *   **Focus**：输入框光晕统一使用 `ring-blue-500`。

---

## 5. 参考实现 (Reference Implementation)

*   **后台列表交互标准**：[admin_forum_categories_list.htm](/plugins/well_forum/views/htm/admin_forum_categories_list.htm)
*   **前端 AJAX 回复与点赞**：[forum_thread_detail.htm](/plugins/well_forum/views/htm/forum_thread_detail.htm)
