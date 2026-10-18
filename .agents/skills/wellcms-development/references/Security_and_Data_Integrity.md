# WellCMS 3.0 安全与业务严谨性 (Security & Integrity)

## 1. 双态 CSRF Token 闭环
*   **路由声明**：登录态 POST 必须声明 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]`；允许访客访问的 POST 必须声明 `'requiresToken' => ['enable' => true, 'ttl' => 600]`，不得叠加 `requiresCsrf`。分别由 `CsrfMiddleware`、`TokenMiddleware` 在路由层自动完成。
*   **控制器零代码**：控制器 **严禁** 调用 `verifyCsrfToken()` 手动验证，token 仅需传入模板供前端携带。
*   **访客状态 (Guest)**：表单与 AJAX 参数统一使用 `_token`。
*   **登录状态 (Member)**：表单与 AJAX 参数统一使用 `_csrf_token`。
*   **校验逻辑**：`CsrfMiddleware` 仅处理登录用户的 `_csrf_token`；`TokenMiddleware` 处理访客 `_token`。以当前中间件源码和论坛路由实现为准。

## 2. 数据处理与强类型化标准
*   **ID 转化**：整数 ID 按真实字段合同读取，`RequestUtils::param('id', 0)` 已完成整数转换，不重复强转。UUID/二进制主键按其格式校验并转换，严禁强转整数破坏标识。
*   **配置空值校验**：先区分缺失与合法禁用值，必要键用 `array_key_exists()` 等按真实合同检查，再校验类型和值域。`0`、`false` 或允许的空字符串不能用 `empty()` 一律当作缺失；可选功能未配置沿主程序默认行为，不强制配置 Redis/云插件。
*   **二进制主键与哈希 (Binary Storage)**：
    *   **统一格式**：应用层传递标准字符串（Hex/IP），入库前转二进制，取出后在 `format()` 中还原。
    *   **IP 处理**：入库执行 `IpHelper::normalizeIp()` 或 `IpHelper::ip()`，取出执行 `IpHelper::bin2ip()`。
    *   **UUIDv7 处理**：入库执行 `UuidHelper::toBinary()`，取出执行 `UuidHelper::fromBinary()`。
    *   **SHA256 处理**：入库执行 `hex2bin()` (32字节)，取出执行 `bin2hex()` (64字符)。
    *   **安全合规**：二进制数据存入 Session 或 Cache 前，**必须** 执行 `bin2hex()` 转换为 UTF-8 兼容字符串，防止序列化失败。

## 3. 极致协程隔离 (Strict Isolation)
*   **严禁类静态属性**：严禁在 Service 中使用 `static` 属性存储请求级数据。
*   **上下文快照**：Service 应在 `captureContext` 中锁定请求快照，逻辑层严禁直接调用全局辅助函数。
*   **UUIDv7 十六进制化**：缓存二进制主键时，Key 必须执行 `bin2hex`，输出时前端通过 `format` 补全 `id_hex`。

## 4. 参考实现 (Reference Implementation)
*   **IP 转换标准**：[ForumThreadService.php](/plugins/well_forum/Services/ForumThreadService.php)
*   **UUIDv7 二进制处理**：[UuidHelper.php](/src/Utils/UuidHelper.php)
*   **SHA256 二进制与 Session 安全**：[UploadService.php](/app/Services/Storage/UploadService.php)
*   **CSRF 中间件标准**：[CsrfMiddleware.php](/app/Middleware/CsrfMiddleware.php)
*   **权限校验标准**：[AuthMiddleware.php](/app/Middleware/AuthMiddleware.php)
