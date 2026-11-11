# 03 · 变迁路由

路由解析是**纯函数**：图进 → `FlowResult` 出，无 IO、不读库、不发事件。宿主拿到结果后在事务内落库。

两套解析器并行存在：

- `TransitionResolver`（v1，`RouteGraph`，seq 定位）—— 兼容旧 `resolveNext` 契约，用于影子比对 / 灰度。
- `FlowTransitionResolver`（v2，`FlowGraph`，node_key 定位）—— 切流完成后的目标实现。

---

## v1 · `TransitionResolver`

`resolve(RouteGraph $graph, int $fromSeq): FlowResult`

| 场景 | 规则 | 返回 |
|------|------|------|
| 无正向出边 | 取 `nextSeqInOrder`（seq 顺延隐式直线） | `straight` / `tail` |
| 有 type=1 直线 | 直达 `to_seq`（**显式直线优先于隐式顺延**，修复 D4） | `straight` |
| 有 type=2 分叉 | 返回全部分支候选与权重（**只预览不选支**） | `branch` |
| 有 type=3 汇合 | 汇合后下一站 | `straight` |

分支权重预览（`branchResult`）：

- 权重全空 → 均分（`weight=1`）。
- 显式配了权重但合计 `≤ 0` → 抛 `ConfigurationException('mes_route_topo_weight_zero')`。

### `pickBranch(FlowResult $branch, string $seed): FlowResult`

- 算法：`crc32($seed) % max(1,10000) % $total`，按累计权重选支。
- 与旧 `pickBranch` 同算法，保证影子比对一致。
- 同种子恒定同支；权重全空时退化为平均权重。

### `resolveRework(RouteGraph, int $fromSeq, string $action='report_ng', ?string $defectCode=null): ?int`

- 仅 `action === 'report_ng'` 生效（TR-011/012）。
- 在 `from_seq` 的 type=4 边中，取 `auto_on_ng=true` 且 `defect_codes` 命中（空=任意不良，否则需包含 `$defectCode`）的第一条 → 返回 `to_seq`，否则 `null`。

---

## v2 · `FlowTransitionResolver`

`resolve(FlowGraph $graph, string $fromNodeKey, FlowContext $ctx): FlowResult`

1. 节点未知 → `blocked('mes_flow_node_unknown', $fromNodeKey)`。
2. 是尾节点 → `tail()`。
3. 收集候选出边：必须 `isForward()`、且 `mode !== rework`（返工回边走 `reportNg` 专用路径）；再经过 `triggerMatches()` 与 `conditions->matches()` 过滤。
4. 候选为空 → `blocked('mes_flow_no_transition', $fromNodeKey)`。
5. 候选首项 `mode === branch` → `branchStrategy->choose()` 选支，返回 `branchTo($targets, $totalWeight)`；否则 `straightTo($first->toNodeKey, $first->edgeKey)`。

> **与 v1 的关键差异**：v2 没有 `nextSeqInOrder` 隐式 seq 顺延——v1 回填时已把「无显式出边则 seq 顺延」物化为显式 `straight` 边（`FlowGraphParser::fromV1`）。

### `triggerMatches(FlowEdge, FlowContext)`

| `ctx->trigger` | 接受的边 `trigger` |
|----------------|--------------------|
| `''` 或 `manual` | `manual` / `auto` |
| 其它（如 `auto_ng` / `event` / `timer`） | 严格等于该值 **或** `auto` |

---

## 分支策略 `BranchStrategyInterface`

`choose(array $edges, string $seed): array` —— 返回「命中的出边列表」（并行分叉可命中多条）。

默认实现 `StableHashBranchStrategy`：

- 空列表 → 空；单条 → 该条。
- 按 `weight()` 加权，`crc32($seed) % (sum*100)/100` 选支；`sum ≤ 0` 退化为等权（默认 100）。
- **确定性**：同种子恒定走同一支，适合「同一 SN 始终走 A 线 / 另一 SN 走 B 线」。

宿主可实现自定义策略（如按实时站荷、按日期分桶）并注册到 `ExtensionRegistry::branchStrategies`。

---

## 条件求值 `ConditionEvaluator`

统一入口：`matches(FlowEdge $edge, FlowContext $ctx): bool`

求值顺序：

1. `rules`：**白名单规则**，每条都必须通过（AND 语义）。
2. `expr`：受限表达式；为空或空串则直接通过。

### rules 规则对象

```json
{ "key": "qty", "op": "gt", "value": 10, "args": {} }
```

- `key` 命中已注册的 `ConditionFn`（`key()`）→ 调用 `evaluate($ctx, $args)` 得实际值；
- 否则 `key` 作为**变量路径**，在 `$ctx->variable / property / unit / card / extra` 中按 `.` 分段查找（首个非空命中）。
- `op` 支持：`>` `>=` `<` `<=` `==` `!=` 及别名 `gt/gte/lt/lte/eq/ne`，以及集合语义 `in` / `not_in`。

### expr 表达式

受限递归下降求值，仅允许：

- `#变量`（变量名，同上多源查找）、字面量（数字 / `'str'` / `"str"` / `true` / `false` / `null`）；
- 比较运算 `> >= < <= == !=`、逻辑 `&& ||`、一元 `! -`、括号 `()`；
- **禁止函数调用与任意代码**（无 `eval`、无函数符号）。

非法表达式抛 `ConfigurationException('mes_flow_condition_invalid:...')`；未知操作符抛 `mes_flow_condition_invalid_op:<op>`。

### 扩展条件函数

实现 `contracts\ConditionFn`（`key()` + `evaluate($ctx, $args)`），注册到 `ConditionEvaluator::register()`（或经 `ExtensionRegistry::conditions`）。核心不做任何业务判断，所有业务条件由宿主插件扩展。
