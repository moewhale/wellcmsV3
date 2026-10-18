# WellCMS 3.0 数据库与事务规范 (Database & Transaction Standards)

## 0. Model 表名规范 (Table Naming)

*   **`$table` 严禁携带表前缀**：Model 的 `protected $table` 属性必须填写**不含 `well_` 前缀**的纯表名。前缀由底层 `DatabaseInterface` 在运行时自动拼接。
*   **示例**：
    ```php
    // 正确：纯表名
    protected $table = 'multi_site_sync_outbox';  // DB 层拼接为 well_multi_site_sync_outbox

    // 错误：携带 well_ 前缀
    protected $table = 'well_multi_site_sync_outbox';  // DB 层拼接为 well_well_multi_site_sync_outbox
    ```
*   **install.php 建表**：使用 `{$db->prefix}表名` 格式，与 Model 的 `$table` 形成一致（`$db->prefix` 输出 `well_`）。

### 0.1 主键字段命名规范 (Primary Identifier Naming)

*   **记录主键 ID 只能命名为 `id`**：凡字段承担实体、操作或资料记录的主键 ID 语义，严禁命名为 `operation_id`、`user_id`、`token_id` 等业务名。主键值可以是自增整数或 UUID，但字段名不随值类型、表名或业务用途变化。
*   **关联 ID 保留业务前缀**：关联字段、外键和业务定位字段允许并应使用 `operation_id`、`article_id`、`user_id` 等明确名称。它们不得与当前表自身的 `id` 主键混淆。
*   **严格一对一扩展表共享主键**：`article_author_profile` 这类与父实体严格一对一的资料表，`id` 直接保存父实体 ID（例如 `well_user.id`），禁止再创建数值相同的 `user_id` 冗余字段；所有查询和写入均直接使用 `id`。
*   **非分区记录表**：主键 ID 必须为 `PRIMARY KEY (id)`。自然维度键按其合同处理，`month` 等非 ID 字段不因本规则被机械改名。
*   **分区表**：主标识仍必须是首列 `id`；仅为满足 MySQL“所有唯一键包含分区键”的限制，允许在 `id` 后追加实际分区列，例如 `PRIMARY KEY (id, created_at)`。不得把分区键前置，也不得把业务外键当作主标识。
*   **全链同步**：调整主键字段时必须同步 `install.php`、Model/原子 Service 默认键、所有查询条件与字段列表、游标、Job、测试夹具和当前数据库结构；关联表中的同名业务外键不得机械改名。

## 1. 原子服务层架构 (Atomic Service)
所有物理表必须 1:1 映射一个原子 Service。职责划分为：
*   **原子操作封装**：仅调用 `$this->dbModel`，无复杂逻辑。
*   **业务逻辑封装**：包含 `readByCache`、`format` 及数据合并。

### 标准接口签名

以当前 `app/Models/BaseModel.php`、`plugins/well_forum/Services/ForumThreadService.php` 和 `ForumCategoryService.php` 为事实源。`insert()` 返回新记录 ID，不是受影响行数；整数 ID 原子 Service 声明 `: int`，其他 ID 合同按驱动真实返回值处理。`bulkInsert/update/bulkUpdate/delete` 返回 `int` 受影响行数，不能先转为 `bool`；需要确认只更新一条时检查返回值是否为 `1`。具名业务方法（如 `deleteById(): bool`）按自身实际合同处理，不与原子 CRUD 返回类型混淆。

*   `insert(array $data): int`（整数 ID 服务：返回新记录 ID）
*   `bulkInsert(array $data): int`
*   `update(array $condition, array $update): int`（实际受影响行数；已有 ID 缓存按其合同失效）
*   `bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int`（实际受影响行数；已有 ID 缓存按其合同失效）
*   `delete(array $condition): int`（实际受影响行数；已有 ID 缓存按其合同失效）
*   `read(array $condition = [], array $orderBy = [], array $fields = ['*']): array`
*   `find(array $condition = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array`
*   `count(array $condition = []): int`

上面的读取参数展示当前 BaseModel 的通用合同。具体 Service 可以按真实调用保留显式必传参数或只暴露所需参数；`$orderBy`、`$key` 默认值以该 Service 的当前查询及输出合同为准，不得机械覆盖论坛的 `created_at` 排序或 `id` 键默认值。`read/find` 的排序、字段选择及结果键参数差异本身不构成返回类型冲突，调整时须校对全部调用方。

## 2. 缓存治理与锁策略
*   **缓存读取**：可缓存的读取复用已有 `readByCache/cacheWithLock` 和主程序统一 `CacheInterface`；主库即时读取、事务校验不得强制走缓存。支持主程序 MySQL 缓存实现，不要求配置 Redis，禁止直接调用 Redis。
*   **主动清理**：按实际缓存键和变更涉及的 ID 失效，`bulkUpdate` 从更新数据取得目标 ID；不存在对应缓存时不新增失效链路。不得猜测 Session 缓存缺口或连带清除 Session 数据。

## 3. 排序与性能确定性 (ORDER BY)
*   **单字段限制**：严禁多字段排序。`$orderBy` 必须仅含一个键值对。
*   **数值规范**：`1` 代表 ASC，`-1` 代表 DESC。
*   **索引依赖**：排序字段必须是索引的一部分。

## 4. 大数据分区准则 (Industrial Partitioning)
根据业务负载，系统强制执行以下三级分区策略：

*   **一级：核心内容 (Core Content)**：
    *   **策略**：`BigInt` (自增) + Quarterly `RANGE (created_at)`。
    *   **适用**：`forum_thread`, `forum_reply`。解决数据周期性归档与单表 B+ 树层级过深。
*   **二级：高频轨迹/社交 (High-Freq User Data)**：
    *   **策略**：`UUIDv7` + **复合分区**（Quarterly `RANGE (created_at)` + `SUBPARTITION HASH (user_id)`）。
    *   **适用**：`forum_message`, `forum_stats_log`。`RANGE` 解决生命周期，`HASH` 解决特定用户的大规模并发写入与查询裁剪。
*   **三级：系统审计/流水 (Auditing & Logs)**：
    *   **策略**：`UUIDv7` + Monthly `RANGE (created_at)`。
    *   **适用**：`system_log`, `login_history`。极致提升冷热数据物理分离性能。

### 强制技术细节
1.  **分区命名**：统一采用 `pYYYYQX` 或 `pYYYYMM`。
2.  **主键规范**：分区键 **必须** 包含在 `PRIMARY KEY` 声明中，严禁在分区表上创建未包含分区键的 `UNIQUE KEY`。

## 5. 跨数据库兼容性 (MySQL & PostgreSQL)
*   **字段定义禁令**：状态/类型字段，MySQL 必用 `tinyint`，PostgreSQL 必用 `smallint`。严禁使用字符类存储状态。
*   **索引匹配**：数组 `$condition` 键名顺序必须与索引定义 100% 匹配，严禁分库乱序。

### 5.1 当前插件 DDL 唯一格式

*   **安装控制流**：100% 以当前 `plugins/well_forum/install.php` 为准，逐表执行 `findTable('无前缀表名')`，不存在时直接 `CREATE TABLE `{$db->prefix}表名`` 并 `$db->exec($sql)`；严禁 `%s`、通用建表包装器和运行期兼容分支。`install.php` 保持当前完整 schema，`upgrade.php` 执行已有安装的必要增量，不另建迁移框架。
*   **表与字段注释**：每张表必须有紧邻 `findTable()` 分支的 PHP 语义注释；每个字段必须在 SQL 同行尾使用 `-- ...` 说明语义。严禁 `COMMENT '...'`。所有 `tinyint` 枚举必须在同行注释列出完整值域及含义。
*   **字符串上限**：`varchar` 长度不得超过 255；超过该上限的内容必须重新裁定数据边界，禁止用 `varchar(500)`、`varchar(1000)` 等规避设计。
*   **结构化数据**：禁止数据库 `json` 字段类型。同一张表最多一个 `text`、`mediumtext` 或 `longtext` 字段；多项结构化数据组合成一个数组后序列化存入该字段。
*   **分类码类型**：`status`、`type`、`code`、`action`、`event`、`object`、`result`、`reason` 等状态或分类码在 MySQL 必须使用 `tinyint`，禁止 `varchar` 或数据库 ENUM。
*   **索引最小化**：每个索引必须能指出实际调用方法、完整 `$condition` 前缀和单一 `$orderBy` 字段；没有真实查询的索引必须删除。严禁为“可能以后使用”预建索引。
*   **全链一致**：DDL 字段、Model、唯一原子 Service、复合 Service、Job、Controller、模板和测试必须使用同一当前字段合同；禁止运行期旧字段识别、映射、双读、双写或兼容链路；安装升级中的存在性检查是生命周期处理，不属于运行期兼容。

## 6. 严禁 SQL 高级语法（铁律）
*   **绝对禁止 JOIN**：严禁使用 `JOIN`、`LEFT JOIN`、`RIGHT JOIN`、`INNER JOIN`、`CROSS JOIN` 等任何连表查询语法。
*   **绝对禁止子查询**：严禁在 `WHERE`、`SELECT`、`FROM` 中使用嵌套子查询（Subquery）。
*   **绝对禁止视图（VIEW）**：严禁创建或使用数据库视图。
*   **绝对禁止存储过程/函数/触发器**：所有业务逻辑必须在应用层（PHP Service）实现，严禁下沉到数据库层。
*   **单表查询 + 应用层合并**：多表数据关联必须通过原子 Service 逐表查询后，在 PHP 层通过 `array_merge`、`array_key_exists` 等方式合并。例如：先查索引表获取 ID 列表，再查主表获取详情，最后循环合并。

## 7. Model 层与 BaseModel 规范

### 7.1 BaseModel 提取策略
所有标准 Model **必须**继承 `App\Models\BaseModel`，通过 `protected $table` 声明表名，禁止在每个方法中硬编码表名字符串。

```php
namespace Plugins\well_forum\Models;

use App\Models\BaseModel;

class ForumCategoryModel extends BaseModel
{
    protected $table = 'forum_category';
}
```

BaseModel 方法须按真实合同声明返回类型，已有声明不得删除。以下是当前源码签名；`insert()` 当前未声明原生返回类型，新增或调整其类型须单独核对驱动及全部调用方，本次规范更新不授权改源码。子类无特殊需求时不得重复覆盖：
*   `insert(array $data = [])`
*   `update(array $condition = [], array $update = []): int`
*   `read(array $condition = [], array $orderBy = [], array $fields = ['*']): array`
*   `find(array $condition = [], array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*']): array`
*   `delete(array $condition = []): int`
*   `count(array $condition = []): int`
*   `maxid(string $field = 'id'): int`
*   `bulkInsert(array $data = []): int`
*   `bulkUpdate(array $update = [], string $keyColumn = 'id', array $wheres = []): int`

### 7.4 `update()` 增量/减量语法铁律
WellCMS Query Builder 的 `compileUpdate` 通过**键名后缀**识别增量/减量操作，**不支持**嵌套数组语法。

**正确语法** (必须)：
```php
// ✅ 增量：键名以 '+' 结尾
$this->dbModel->update(['id' => $id], ['downloads+' => 1]);
// 生成 SQL: UPDATE ... SET `downloads` = `downloads` + ? WHERE `id` = ?

// ✅ 减量：键名以 '-' 结尾
$this->dbModel->update(['id' => $id], ['stock-' => 1]);
// 生成 SQL: UPDATE ... SET `stock` = `stock` - ? WHERE `id` = ?

// ✅ 普通赋值
$this->dbModel->update(['id' => $id], ['name' => 'New Name']);
```

**错误语法** (严禁)：
```php
// ❌ 错误：嵌套数组语法不被支持
$this->dbModel->update(['id' => $id], ['downloads' => ['+=' => 1]]);
// 实际生成 SQL: UPDATE ... SET `downloads` = 'Array' WHERE `id` = ?
// 后果：在 MySQL strict mode 下 PDO 抛异常，导致整个请求失败
```

**关键认知**：
*   `paramsValueEscape()` 对数组执行强制类型转换 `(string)$value`，数组会变成 `"Array"` 字符串。
*   所有方言（MySQL / PgSQL / SQLite / SqlServer）均遵循相同的键名后缀规则（`+` / `-`）。
*   `bulkUpdate` 同理：行数据中使用 `'views+' => 5` 而非 `['views' => ['+=' => 5]]`。

### 7.2 `find()` 的 `$key` 参数语义
*   `$key = ''`（默认值）：返回**索引数组** `[0 => row, 1 => row]`。
*   `$key = 'id'`：返回以指定字段为键的**关联数组** `['id1' => row, 'id2' => row]`（底层使用 `array_column`）。
*   **不可随意变更默认值**：若原子 Model 历史默认值为 `'id'`，继承 BaseModel 后必须通过覆盖 `find()` 保持该默认值，否则会导致调用方按 ID 索引的代码失效。

### 7.3 继承 BaseModel 的边界

按 PHP 7.2 的实际签名兼容规则判断，见 [Compatibility_and_Tools.md](./Compatibility_and_Tools.md)。新增可选参数、父类无返回类型而子类添加正确类型，或增加 `cursorFind()`、`cleanupExpired()` 等自定义方法，本身不妨碍继承。不得据此复制整套 CRUD。

父类 `insert(array $data = [])` 是可选参数，子类不能改为必传；父类已有返回类型也不能随意删除或更换。确有签名或数据库语义不兼容且不能合理复用时才保留独立实现。兼容的子类仅保留自定义方法及必要的默认值覆盖。

## 8. 参考实现 (Reference Implementation)
*   **基础原子服务标准**：[UserService.php](/app/Services/Auth/UserService.php)
*   **复合业务与 Service 协作标准**：[ForumThreadService.php](/plugins/well_forum/Services/ForumThreadService.php)
*   **BaseModel 提取标准**：[BaseModel.php](/app/Models/BaseModel.php)
*   **插件 Model 继承范例**：[ForumCategoryModel.php](/plugins/well_forum/Models/ForumCategoryModel.php)
