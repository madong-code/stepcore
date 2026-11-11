# 02 · 数据模型

所有模型均为 **`readonly` 值对象**，不可变；需要「修改后的副本」请使用 `with()`（或 `FlowResult` 的 `replay()` 这类工厂）。下面列出公共字段契约，实现细节以 `src/model/*` 为准。

---

## v2 编译图（`FlowGraph` 族）

切流完成后的目标形态：节点以 `node_key` 定位，变迁以 `from_node_key`/`to_node_key` 描述，业务参数统一放在 `properties`。

### `FlowNode`

| 字段 | 类型 | 说明 |
|------|------|------|
| `nodeKey` | `string` | 稳定标识（必填，非空） |
| `type` | `string` | 节点类型：`step` / `start` / `end` / `decision` / `split` / `join` / `fa_rework`（亦支持插件扩展类型） |
| `name` | `string` | 展示名 |
| `properties` | `array` | 业务参数（按类型的 propertySchema 校验） |
| `steps` | `list<{step_key, properties}>` | 工步列表 |
| `ext` | `array` | 插件扩展命名空间 |

- `ROUTING_TYPES = ['start','end','decision','split','join']`：**不产生节点任务**的纯路由类型。
- `isExecutable()`：非路由类型即为可执行节点。
- `isEnd()`：`type === 'end'`。
- `property($key, $default)` / `stepKeys()` / `toArray()`。

### `FlowEdge`

| 字段 | 类型 | 说明 |
|------|------|------|
| `edgeKey` | `string` | 边标识（必填，全局唯一） |
| `fromNodeKey` / `toNodeKey` | `string` | 端点（必填） |
| `mode` | `string` | 见下方 `MODE_*` |
| `properties` | `array` | `trigger` / `condition` / `priority` / `weight` / `label` / `style` / `enabled` / `guards` / `actions` |
| `ext` | `array` | 扩展 |

`MODE_*` 常量：

| 常量 | 值 | 含义 |
|------|----|------|
| `MODE_STRAIGHT` | `straight` | 直线 |
| `MODE_BRANCH` | `branch` | 并行分叉 |
| `MODE_JOIN` | `join` | 汇合 |
| `MODE_REWORK` | `rework` | 返工回边 |
| `MODE_CONDITIONAL` | `conditional` | 条件路由 |
| `MODE_EVENT` | `event` | 事件触发 |
| `MODE_TIMER` | `timer` | 定时器触发 |
| `MODE_ROLLBACK` | `rollback` | 回退 |
| `MODE_JUMP` | `jump` | 跳转 |
| `MODE_CANCEL` | `cancel` | 取消 |

- `COMPENSATION_MODES = [rollback, jump, cancel]`：`isForward()` = `isEnabled() && !in_array(mode, COMPENSATION_MODES)`。
- `isEnabled()`：默认 `true`（读 `properties['enabled']`）。
- `priority()`：默认 `100`。
- `triggerType()`：读 `properties['trigger']['type']`，缺省 `manual`。
- `condition()`：返回 `{expr, rules}`。
- `weight()`：分叉权重，可为 `null`。

### `FlowGraph`

构造即校验，非法直接抛 `ConfigurationException`：

- 节点 `nodeKey` 重复 → `mes_flow_node_key_duplicated:<key>`。
- 边 `edgeKey` 重复 → `mes_flow_edge_key_duplicated:<key>`。
- 边引用了不存在的节点 → `mes_flow_edge_dangling:<key>`。

构造时对每个节点的 `outEdges` 按 **priority 降序、edge_key 升序** 排序，解析阶段直接取首项即最高优先级。

公共方法：

| 方法 | 说明 |
|------|------|
| `node($k)` / `has($k)` / `nodes()` | 节点访问 |
| `outEdgesFrom($k)` | 已排序的出边 |
| `inEdgesTo($k)` | 入边 |
| `edges()` / `nodeCount()` | 遍历 |
| `isTail($k)` | **无前向出边**或显式 `end` 类型；注意：即便某前向边 `enabled=false`，也交给 Resolver 判 `blocked`，不误判为终点 |
| `startNodeKey()` | `type='start'`，否则首个无入边节点 |
| `validate()` | 返回问题码数组（空数组=合法）：`mes_flow_graph_no_start` / `mes_flow_graph_unreachable:<key>` / `mes_flow_graph_join_unpaired:<key>` |
| `toArray()` | `{nodes, transitions}` |

---

## v1 路线图（`RouteGraph` 族）

兼容 / 回填用途，宿主快照（如 `RouteService::routeSnapshot`）经 `RouteGraphParser::fromSnapshot` 转换。seq 定位 + 隐式顺延。

### `RouteNode`

`seq` / `key` / `processId` / `attrs`（工序属性透传）。

### `RouteEdge`

`key` / `fromSeq` / `toSeq` / `type`(`TransitionType`) / `weight?` / `condition`（白名单 DSL：`auto_on_ng` / `defect_codes` / `qty_below` / `yield_below` / `branch_by`）。

### `RouteGraph`

- 重复 `seq` → `mes_route_topo_duplicate_seq`。
- 索引：`outForward`(type 1/2/3) / `outRework`(type 4) / `inbound`。
- `nextSeqInOrder($seq)`：seq 升序的下一站（隐式直线）。
- `isTail($seq)`：无正向出边且无隐式下一站。
- `inboundBranchCount($seq)`：汇合就绪 / branch_key 语义用。

---

## `FlowContext`（一次动作的全部输入）

值对象，`with($overrides)` 派生副本（未知字段抛 `InvalidArgumentException`）。v2 字段追加在末尾以兼容既有位置参数调用。

| 字段 | 类型 | 说明 |
|------|------|------|
| `action` | `string` | 动作名（决定守卫名单与动作处理） |
| `woId` / `routeId` / `processId` | `string` | 工单 / 路线 / 工序 |
| `seq` | `int` | v1 定位（兼容期） |
| `unitNos` / `unitNo` | `array` / `string` | 批量/单单元号 |
| `unit` / `card` | `array` | 单元快照 / 流转卡快照（宿主注入） |
| `operator` / `terminalCode` | `string` | 操作工 / 终端 |
| `defectCode` / `remark` | `string` | 不良码 / 备注 |
| `toSeq` | `?int` | 目标 seq（v1） |
| `seed` | `string` | 分叉选支种子（缺省回落 `unitNo`） |
| `idempotencyKey` / `clientRequestId` | `?string` / `string` | 幂等键 / 客户端请求号 |
| `extra` | `array` | 动作私有输入（如 `skill` 预载结果） |
| `nodeKey` / `instanceId` / `taskId` / `tokenId` | `string` | v2 定位键 |
| `trigger` | `string` | 默认 `manual`（manual/auto/auto_ng/event/timer/...） |
| `variable` / `properties` | `array` | 条件求值变量源 |
| `reasonCode` / `targetNodeKey` / `rollbackMode` | `string` | 回退用 |

便捷方法：

- `positionKey()`：优先 `nodeKey`，回落 `seq`。
- `branchSeed()`：优先 `seed`，回落 `unitNo`（保证同 SN 恒定同支）。

---

## `FlowResult`（统一返回结构）

`readonly`，提供静态工厂。v2 字段追加在末尾，兼容 `get_object_vars + new self(...)` 派生。

| 字段 | 类型 | 说明 |
|------|------|------|
| `type` | `string` | `tail` / `straight` / `branch` / `rework` / `blocked` / `rollback` / `cancel` |
| `seq` / `process` | `?int` / `?array` | v1 结果 |
| `branches` / `totalWeight` / `branchKey` | 分支候选 / 权重和 / 选中支 |
| `replayed` | `bool` | 是否为重放（幂等命中） |
| `warnings` | `list<{name,errorCode,detail}>` | 非阻断告警 |
| `errorCode` / `errorDetail` | `?string` | 阻断时填 |
| `extra` | `array` | 扩展（如 `branchTo` 的 `targets`） |
| `nodeKey` / `edgeKey` / `toNodeKey` | `?string` | v2 定位 |
| `rollbackMode` / `reasonCode` | `?string` | 回退模式 / 原因码 |

工厂方法：

| 工厂 | 语义 |
|------|------|
| `tail()` | 流程终点 |
| `straight($seq, $process)` | 直线到 seq（v1） |
| `branch($branches, $totalWeight)` | 分支候选（v1） |
| `rework($toSeq)` | 返工到 seq（v1） |
| `blocked($errorCode, $detail)` | 阻断 |
| `straightTo($toNodeKey, $edgeKey)` | 直线到 node_key（v2） |
| `branchTo($targets, $totalWeight)` | 并行分叉目标列表（v2）；`v2Targets()` 取 `extra.targets` |
| `reworkTo($toNodeKey, $edgeKey)` | 返工/回边到 node_key（v2） |
| `rollback($toNodeKey, $mode, $edgeKey)` | 回退（type=rollback，mode 区分） |
| `replay($original)` | 幂等重放，复制原结果并置 `replayed=true` |
| `canceled($reasonCode)` | 不可恢复取消（type=cancel） |
| `toLegacyArray()` | 兼容旧 `TransitService::resolveNext` 输出（影子比对 / 灰度契约） |

---

## `FlowToken` / `FlowTask`（运行态）

| 类 | 状态常量 | 关键方法 |
|----|----------|----------|
| `FlowToken` | `ACTIVE=1` / `WAITING_JOIN=2` / `CONSUMED=3` | `isActive()` / `consume()`(→CONSUMED) / `reborn($newId,$toNodeKey,$edgeKey,$branchKey)`(reentryCount+1) / `with()` |
| `FlowTask` | `PENDING=1` / `RUNNING=2` / `FINISHED=3` / `CANCELED=4` / `SUSPENDED=5` | `isOpen()`(PENDING/RUNNING/SUSPENDED) / `finish()` / `reopen()` / `with()` |

令牌物化「当前在哪」，支持分叉/汇合/返工重入，替代 v1 的单点 `seq` 推进。
