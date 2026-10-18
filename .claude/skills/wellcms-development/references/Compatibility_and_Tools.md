# WellCMS 3.0 编码兼容性与调试工具 (Tools & Compatibility)

## 1. PHP 7.2+ 兼容性标准
*   **属性声明限制**：严禁使用 PHP 7.4+ 的类属性强类型声明（如 `protected string $name;`）。必须使用 DocBlock 代替。
*   **全局类限定**：内置全局类（如 `\Exception`, `\Throwable`）必须带前缀反斜杠，防止命名空间冲突。
*   **IDE 友好**：代码必须消除所有 IDE 级警告，红色报错为禁令。

### 1.1 继承与方法覆盖兼容性（PHP 7.2）

声明返回类型，遵循 [数据库规范](./Database_Standards.md) 的真实合同；不能以 PHP 7.2 为由批量删除 BaseModel 或子类已有的返回类型。

| 维度 | 规则 |
|------|------|
| 参数数量 | 子类不能增加必传参数或删掉父类可接受的参数；可以增加可选参数，不要求参数数量完全相同。 |
| 参数类型与默认值 | 保持 PHP 7.2 可兼容的声明，不依赖 PHP 7.4 的参数逆变；父类可选参数不能在子类变为必传。兼容范围内可调整默认值，但须保留调用合同。 |
| 返回类型 | 父类已声明时遵循 PHP 7.2 的类型兼容要求，不依赖 PHP 7.4 的协变；父类未声明返回类型并不禁止子类增加返回类型。新增类型必须覆盖真实返回值。 |

BaseModel 的 `update/delete/bulkInsert/bulkUpdate/count/maxid` 使用 `: int`，`read/find` 使用 `: array`。当前 `insert()` 尚无原生返回类型，返回新记录 ID；其返回范围须沿驱动合同判断，不能在规范修订时擅自改为受影响行数、`bool` 或修改基类源码。类型固定为整数 ID 的原子 Service 使用 `insert(...): int`。

子类需要特殊默认值时，仅覆盖必要方法并代理 `parent`；额外可选参数或新增 `cursorFind()` 等方法本身不构成禁止继承的理由。只有实际签名或数据语义不兼容且无法合理复用时才保留独立实现。

## 2. RequestUtils 类型自动转换
`RequestUtils` 根据第二个参数（默认值）的类型自动执行安全过滤。
*   `null`：返回原始（带转义）数据。
*   **0 (数字)**：自动执行 `(int)` 强制转换。
*   **'' (空字符串)**：自动执行 `(string)` 转换并应用 `htmlspecialchars`。
* **[] (数组)**：自动对数组内每个元素应用对应转换。
* **[0] (数组)**：自动对数组内每个元素执行 `(int)` 强制转换。
* **[''] (数组)**：自动对数组内每个元素执行 `(string)` 转换并应用 `htmlspecialchars`。

## 3. 调试与性能调优 (Debug & Optimization)
*   **DEBUG 模式**：
    *   `1`：记录日志，显示友好错误页面。
    *   `2`：显示完整条用栈，记录 SQL 查询明细。
*   **慢 I/O 监控**：日志会自动记录超过 200ms 的外部调用。
*   **数据驱动透明**：严禁耦合 MySQL 特有语法，必须通过底层的 `Grammar` 适配器保证兼容性。

## 4. 工具类迁移
*   **验证器**：优先使用 `Framework\Utils\Validator`。
*   **随机安全**：使用 `random_int()` 或 `random_bytes()`。

## 5. 参考实现 (Reference Implementation)
*   **RequestUtils 类型转换标准**：[RequestUtils.php](/src/Http/Psr7/RequestUtils.php)
*   **Validator 校验器标准**：[Validator.php](/src/Utils/Validator.php)
