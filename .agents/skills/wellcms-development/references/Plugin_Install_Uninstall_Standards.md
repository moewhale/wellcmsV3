# 插件安装与卸载标准 (Plugin Installation & Uninstallation Standards)

本手册定义了 WellCMS 3.0 插件在安装 (`install.php`)、升级 (`upgrade.php`) 和卸载 (`uninstall.php`) 过程中的物理文件处理及数据库方言编写规范。

## 🟢 安装规范 (install.php)

### 1. 物理文件处理

* **批量复制**：严禁冗长的单行复制，必须使用数组循环配合 `FileHelper::copy`。
* **目录创建**：复制前必须使用 `DirectoryHelper::mkdir()` 确保目标路径存在。

```php
$dest_path = APP_PATH . 'app/views/icon/';
DirectoryHelper::mkdir($dest_path);
$icons = ['facebook.ico', 'instagram.ico'];
foreach ($icons as $icon) {
    FileHelper::copy(APP_PATH . 'plugins/well_tools/views/icon/' . $icon, $dest_path . $icon);
}
```

### 2. 数据库字段扩展 (Altering Tables)

* **循环定义**：将统计字段（user表）和权限字段（group表）封装进数组循环处理。
* **存在性校验**：执行 `ADD COLUMN` 前必须调用 `$db->findField()`，防止重复安装报错。

```php
$user_fields = ['tools_pending', 'tools_approved'];
foreach ($user_fields as $field) {
    if (!$db->findField('user', $field)) {
        $db->exec("ALTER TABLE `{$db->prefix}user` ADD `{$field}` int(11) UNSIGNED NOT NULL DEFAULT '0';");
    }
}
```

### 3. 权限字段更新 (Permission Updates)

* **服务层驱动**：添加新权限字段后，严禁使用 `$db->exec("UPDATE...")`。必须通过 `GroupService` 实现，以确保缓存同步及业务逻辑闭环。
* **严禁修改 SQL 文件**：所有插件相关字段扩展必须包含在插件自身 `install.php` 的当前完整定义中，已有安装的增量由 `upgrade.php` 执行，严禁修改主程序核心 `/install/install.sql`。

```php
if ($field === 'collection') {
    $this->container->get(\App\Services\Auth\GroupService::class)->update(1, [$field => 1]);
}
```

### 4. 数据表架构 (Schema Creation)

* **论坛控制流唯一标准**：100% 以当前 `plugins/well_forum/install.php` 为准。每张表单独调用 `$db->findTable('无前缀表名')`，不存在时在该分支内定义 `$sql = "CREATE TABLE `{$db->prefix}表名` ..."` 并直接 `$db->exec($sql)`。严禁 `%s` 表名、匿名建表闭包、通用 DDL 数组循环或包装方法。
* **安装完整、升级增量**：`install.php` 定义当前完整 schema，升级新增的表和字段也必须同步加入，确保首次安装即可使用全部当前功能。`upgrade.php` 为已有安装执行所需代码、文件、表和字段升级，不能只更新安装文件；禁止运行期双读、双写或旧字段兼容链路。
* **字段和注释标准**：统一使用 `snake_case`，显式声明 `NOT NULL DEFAULT`；每张表使用紧邻建表分支的 PHP 语义注释，每个字段在 SQL 同行尾使用 `-- ...` 注释，严禁 SQL `COMMENT '...'`。`tinyint` 字段注释必须逐值说明完整值域。
* **字段类型上限**：`varchar` 不得超过 255；禁止数据库 `json` 类型；每表最多一个 `text` 系列字段，结构化数组统一序列化到该字段。
* **索引和分区**：索引只服务已存在的实际查询，条件键顺序必须与索引前缀一致且只按一个索引字段排序。达到大数据分区门槛时按 `Database_Standards.md` 添加分区，不得无业务证据预建索引或分区。

---

## 🟡 升级规范 (upgrade.php)

* 按实际版本差异处理必要的代码、文件、数据表和字段；沿用宿主升级入口，不另建迁移框架。
* 使用实际数据库接口检查表、字段及必要的现存定义，只补缺失变更，重复执行不会重复创建；已有定义与目标冲突时明确失败，不能静默视为成功。
* 与 `install.php` 对齐最终结构、默认值、索引及权限合同。权限变化同样通过 `GroupService` 处理；保留实际部署的主库/分片归属检查。
* DDL 可能部分提交，不声称能整体回滚；失败后可按存在性检查重跑。结构升级不自动启动上传、云同步或删除任务。
* 仅检查本次变更涉及的干净安装及已有安装升级结果，不扩大成无关全站测试。

## 🔴 卸载规范 (uninstall.php)

### 1. 精准文件清理

* **严禁盲目删除目录**：除了插件独占的私有目录外，严禁直接使用 `rmdirRecursive(..., true)` 删除公共目录（如 `app/views/icon/`）。
* **两步走策略**：
    1. 逐一删除插件产出的具体文件。
    2. 调用 `DirectoryHelper::rmdir()`（仅在目录为空时才会执行物理删除），确保不影响共用该目录的其他插件。

```php
foreach ($icons as $icon) {
    FileHelper::unlink($icon_path . $icon);
}
DirectoryHelper::rmdir($icon_path); // 安全删除
```

### 2. 字段与表清理

* **逆向循环**：使用与安装对应的列表进行 `DROP COLUMN` 和 `DROP TABLE`。
* **安全删除**：必须在删除前执行 `findField` 或 `findTable` 检测，确保卸载过程的鲁棒性。

## ⚠️ 兼容性铁律

1. **大小写屏蔽**：由于底层驱动开启了 `PDO::CASE_LOWER`，在 `findField` 和 `findTable` 时，框架会自动匹配。SQL 写法应统一遵循小写规范。
2. **前缀引用**：所有 SQL 必须包含 `{$db->prefix}`。
3. **方言转换保护**：DDL 脚本严禁自带双分号或非标准注释，确保 `prepareSchema` 能将其正确转译为 PostgreSQL 等方言。

## 3. 参考实现 (Reference Implementation)

* **标准安装脚本 (install.php)**：[well_forum/install.php](/plugins/well_forum/install.php)
* **标准卸载脚本 (uninstall.php)**：[well_forum/uninstall.php](/plugins/well_forum/uninstall.php)
