# 10 · 图解析与 graph_hash

解析层把「宿主存储态 / 设计器 JSON」转换为内核的 `FlowGraph` / `RouteGraph` 值对象。所有解析器为**纯函数**，无 IO。

## `RouteGraphParser`（v1 快照 → `RouteGraph`）

`fromSnapshot(array $snapshot): RouteGraph`

- 入参为宿主快照（如 `RouteService::routeSnapshot`），结构 `{processes:[...], transitions:[...]}`。
- `processes` → `RouteNode`（取 `seq`/`key`(`node_key`/`code`/`id`)/`process_id`/`attrs`）。
- `transitions` → `RouteEdge`：`type` 支持 `int` 或 `BackedEnum`（`TransitionType::fromMixed`）；权重归一化；`condition` 走 `normalizeCondition()`。
- 重复 `seq` → `mes_route_topo_duplicate_seq`；非法 `type` → `mes_route_transition_type_invalid`。

### 条件白名单 `normalizeCondition()`

只允许登记键：`auto_on_ng` / `defect_codes` / `qty_below` / `yield_below` / `branch_by`。未知键 → `mes_route_transition_condition_unknown_key:<k>`（不静默）。支持 JSON 字符串 / 数组 / null。

## `FlowGraphParser`（设计器 JSON → `FlowGraph`）

`parse(array $content): FlowGraph`：按 `schema_version` 判别。

- `>= 2` → `fromV2()`：直接映射 `nodes[]`（`node_key`/`type`/`name`/`properties`/`steps`/`ext`）与 `transitions[]`/`edges[]`（`edge_key`/`from_node_key`/`to_node_key`/`mode`/`properties`/`ext`，兼容旧 `key`/`from_node`/`to_node` 命名）。
- `< 2` → `fromV1()`：兼容读取与一次性回填，**不落库、不重写历史快照**。

### v1 → v2 转换规则（`fromV1`）

- `node_kind` → `type`：`1 step` / `2 start` / `3 end` / `4 decision` / `5 split` / `6 join` / `7 fa_rework`。
- 业务字段（见 `NODE_BUSINESS_KEYS`：`code`/`station_type`/`work_center_id`/`scan_mode`/`is_key_site`/`qc_gate`/`allow_rework`/`max_rework_times`/`rework_overflow`/`allow_skip`/`is_assembly`/`outsourced`/`standard_hours`/`route_memo`/`step_group`/`custom_type`）迁入 `properties`。
- `rework_to_seq` → `properties.rework_to_node_key`（0/null 记为 null）。
- `steps[].content` / `device_params` / `attachments` 归入 `steps[]`。
- **隐式 seq 顺延物化**：无任何显式前向出边的节点，自动补一条 `straight` 边连到下一个 seq 节点（key 形如 `ed_materialized_<from>_<to>`，`materialized=true`）；已是显式回边目标的节点不重复补。

### `graphHash(array $data): string`

规范化 JSON 的 `sha1`，用于版本校验 / 影子比对：

- 排除 `meta.graph_hash` 与 `layout.viewport`（不参与 hash）。
- 递归 `ksort` 所有键。
- `nodes` 按 `node_key` 升序排列；每个 node 的 `steps` 按 `step_key` 升序。
- `transitions` 按 `from_node_key` / `to_node_key` / `edge_key` 字典序排列。
- 输出 `JSON_UNESCAPED_UNICODE | UNESCAPED_SLASHES | PRESERVE_ZERO_FRACTION` 后 `sha1`。
- 序列化失败 → `mes_flow_graph_hash_failed`。

## v2 JSON Schema

`src/schema/route-graph-v2.json`（draft-07）定义 v2 设计器 JSON 结构：

- 顶层：`schema_version`(const 2) / `meta`(code,version,revision,graph_hash, saved_at) / `nodes`(≥1) / `transitions` / `layout` / `extensions`。
- `node`：`node_key`(`^[A-Za-z0-9_-]{1,64}$`) / `type` / `name` / `properties` / `steps` / `ext`。
- `step`：`step_key` + `properties`(content/device_params/attachments)。
- `transition`：`edge_key` / `from_node_key` / `to_node_key` / `mode`(enum: straight/branch/join/rework/conditional/event/timer/rollback/jump/cancel) / `properties`。
- `transitionProperties`：`trigger`(type enum: manual/auto/auto_ng/event/timer/threshold/rollback/reversal) / `condition`(expr/rules) / `priority` / `weight`(0~100) / `join_policy`(all/any) / `guards` / `actions` / `label` / `style` / `enabled`。

宿主可在保存设计器时按此 Schema 校验，并用 `FlowGraphParser::graphHash()` 生成 `meta.graph_hash` 做内容寻址。
