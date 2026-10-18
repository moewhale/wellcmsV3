# WellCMS 3.0 工业级分页规范 (Pagination Standards)

为了解决高并发、大数据量下分页性能退化及数据跳动问题，大数据内容列表遵循核心第 20 条，复用现有游标分页标准；小型配置列表不因本规范盲目改造。

## 1. 锚点锁 (maxId Anchor)
锚点必须对应现有查询合同，不能把所有名为 `maxId` 的参数都当作时间戳。
- 以 `created_at` 时间快照为锚点的列表，首次获取当前时间戳，后续携带原值并包含 `'created_at' => ['<=' => $maxId]`。
- 以整数 ID 上界为锚点的列表保留其 ID 语义；其他排序/游标按现有 `fetchPaged` 的 `orderKey/indexKey` 和索引合同处理，不添加不存在的时间字段。
- **作用**：锁定查询瞬间的时间快照，防止翻页过程中新插入的数据挤乱原有列表。

## 2. 游标双向探测 (Bidirectional Cursor)
千万级列表必须复用既有 `fetchPaged`，不得使用大 OFFSET；其他已正常运行的分页入口按真实需求和授权范围处理，不另建分页框架。
*   **向下翻页**：使用当前页最后一条数据的 ID 作为 `cursorId`，`dirFlag` 设为 `next`。
*   **向上翻页**：使用当前页第一条数据的 ID 作为 `cursorId`，`dirFlag` 设为 `previous`。

## 3. 统一分页链接 (Unified Pager)
必须在控制器中调用 `buildPaginationLinks` 统一构建分页数据。

### hasNext 判定逻辑
```php
($dirFlag === 'next' ? $hasMore : true)
```
- 向后翻页时使用真实探测结果 `$hasMore`。
- 向前翻页时为了保证 UI 体验，链接状态建议设为 `true`。

## 5. 参考实现 (Reference Implementation)
*   **列表页分页标准**：[ForumController.php](/plugins/well_forum/Controllers/Frontend/ForumController.php) (主题列表加载)
*   **详情页双轴分页标准**：[ThreadController.php](/plugins/well_forum/Controllers/Frontend/ThreadController.php) (回复列表加载)
