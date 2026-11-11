# 11 · 异常

内核异常统一继承自 `StepCoreException`（`RuntimeException` 子类），全部在**事务内**抛出，便于宿主回滚，不产生幽灵事件。

## 层次

```
RuntimeException
└─ StepCoreException                      (src/exception/StepCoreException.php)
   ├─ ConfigurationException              (配置错误：fail-fast，不静默)
   └─ StateException                      (非法状态迁移)
```

## `StepCoreException`

基础异常。引擎算法在包内，异常统一由包抛出（依赖方向：宿主 → 包，单向）。

## `ConfigurationException`

路线图 / 守卫 / 动作 / 解析配置错误，**fail-fast**。常见错误码（与代码一致）：

| 错误码 | 触发点 |
|--------|--------|
| `mes_flow_graph_invalid_node` | `FlowGraph` 构造收到非 `FlowNode` |
| `mes_flow_node_key_duplicated:<key>` | 节点 node_key 重复 |
| `mes_flow_graph_invalid_edge` | `FlowGraph` 构造收到非 `FlowEdge` |
| `mes_flow_edge_key_duplicated:<key>` | 边 edge_key 重复 |
| `mes_flow_edge_dangling:<key>` | 边引用不存在的节点 |
| `mes_route_topo_duplicate_seq` | `RouteGraph` / `RouteGraphParser` 重复 seq |
| `mes_route_transition_type_invalid` | 非法 transition type |
| `mes_route_transition_condition_invalid` | 条件 JSON/数组非法 |
| `mes_route_transition_condition_unknown_key:<k>` | 条件含未登记键 |
| `mes_route_topo_weight_zero` | 分支权重显式配置但合计为 0 |
| `mes_transfer_branch_missing` | `pickBranch` 无可选项 |
| `mes_flow_guard_not_registered:<missing>` | `GuardRegistry::assertRegistered` 启动期 fail-fast |
| `mes_flow_extension_class_missing:<class>` | 扩展类不存在 |
| `mes_flow_extension_contract_mismatch:<class>` | 扩展类未实现契约 |
| `mes_flow_graph_hash_failed` | graph_hash 序列化失败 |
| `mes_flow_condition_invalid[:expr]` | 条件表达式非法 |
| `mes_flow_condition_invalid_op:<op>` | 条件操作符未知 |
| `mes_flow_node_type_required` / `duplicated:<code>` / `unknown:<code>` | 节点类型注册/查询 |
| `mes_flow_rollback_target_invalid` / `no_source` / `no_rework_edge` / `mode_unknown:<m>` | 回退解析 |

## `StateException`

非法状态迁移（正向白名单 / 逆向白名单均不匹配）。

```php
class StateException extends StepCoreException
{
    public function __construct(public string $from, public string $to, public string $event = '')
}
```

错误信息：`mes_flow_state_invalid: <from> -> <to> (event=<event>)`。

触发点：`StateMachine::ensure` / `FlowStateMachine::ensure` / `ensureReverse`（回退逆向校验）。

## 宿主处理建议

- 配置错误（`ConfigurationException`）：启动期即暴露（如 `GuardRegistry::assertRegistered`），不要到运行期才失败。
- 状态错误（`StateException`）：回退边界被突破时抛出，宿主应转译为业务错误码（如 `mes_flow_rollback_denied`）并回滚事务。
- 业务阻断（守卫 `block` / 回退 `can()=false`）**不抛异常**，而是返回 `FlowResult::blocked(...)`，由调用方正常处理。
