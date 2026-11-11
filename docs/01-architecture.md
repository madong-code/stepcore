# 01 · 架构

## 分层

```
┌──────────────────────────────────────────────────────────────┐
│  Host (mes / 任意宿主)                                          │
│  - 实现 stepcore 的接口 (I*Service, GuardInterface, ...)        │
│  - 装配 Engine（MesKernel / 容器）                              │
│  - 提供图快照、持久化、事件消费                                  │
└───────────────────────────▲──────────────────────────────────┘
                            │ 调用 flow()/rollback()
┌───────────────────────────┴──────────────────────────────────┐
│  madong/stepcore  (纯内核，无 IO)                               │
│                                                                │
│  Engine ──► GuardRegistry/Chain ──► ActionRegistry             │
│     │              │                      │                    │
│     ├─► route/*  (TransitionResolver / BranchStrategy)         │
│     ├─► rollback/* (RollbackPolicy / Resolver)                 │
│     ├─► state/*  (FlowStateMachine / StateMachine)            │
│     ├─► parser/* (GraphParser / ConditionEvaluator)           │
│     ├─► registry/* (ExtensionRegistry / NodeTypeRegistry)     │
│     ├─► aop/* (AspectDispatcher)                               │
│     └─► event/* (EventBus / FlowEventBus)                     │
│                                                                │
│  model/*  (FlowGraph/RouteGraph/Context/Result/Token/Task)     │
│  contracts/* (所有可插拔契约)                                   │
│  interface/services/* (宿主需实现的端口 I*Service)              │
└──────────────────────────────────────────────────────────────┘
```

## 依赖方向（硬约束）

- ✅ 宿主依赖包；❌ 包**绝不**依赖宿主（不 `use` 任何 `plugin\*` / `app\*` / `vendor\*` 业务类）。
- 包内 `enum\TransitionType` 自带一份，与宿主 `RouteTransitionType` 对齐但独立，避免反向依赖。
- 所有「宿主必须提供的能力」都抽象为 `interface/services/I*Service` 端口或 `contracts/*` 契约，由宿主注入。

## 目录映射

| 目录 | 角色 |
|------|------|
| `src/Engine.php` | 唯一编排入口 |
| `src/model/` | 值对象：图、上下文、结果、令牌、任务 |
| `src/contracts/` | 所有可插拔契约（Action/Guard/NodeTypeHandler/ConditionFn/BranchStrategy/Aspect/FlowEventListener/FlowModule/RollbackPolicy） |
| `src/action/` | `ActionRegistry` + `ActionInterface` 定义 |
| `src/guard/` | `GuardChain` / `GuardRegistry` / `GuardReport` / `GuardOutcome` |
| `src/route/` | `TransitionResolver`(v1) / `FlowTransitionResolver`(v2) / `RollbackResolver` / `StableHashBranchStrategy` |
| `src/rollback/` | `RollbackContext` / `RollbackPolicy`(默认策略) |
| `src/state/` | `FlowStateMachine`(业务状态) / `StateMachine`(通用) |
| `src/parser/` | `FlowGraphParser` / `RouteGraphParser` / `ConditionEvaluator` |
| `src/registry/` | `ExtensionRegistry` / `NodeTypeRegistry` |
| `src/lifecycle/` | `ModuleManager`(模块生命周期) |
| `src/aop/` | `AspectDispatcher` / `JoinPoint` |
| `src/event/` | `EventBus`(事务后发布) / `FlowEventBus`(进程内订阅) / `FlowEvent` |
| `src/interface/services/` | 宿主需实现的端口：`IEventPublisher` / `IIdempotencyStore` / `IRouteGraphService` / `IWorkOrderService` / `IWipCardService` / `ITraceUnitService` / `IStationLoadService` / `IFlowLogService` |
| `src/schema/route-graph-v2.json` | v2 图 JSON Schema（draft-07） |
| `src/exception/` | `StepCoreException` / `StateException` / `ConfigurationException` |

## 请求生命周期

### flow（过站 / 转序 / 上报 NG …）

```
Engine::flow(ctx)
  │
  ├─ 取 $guardNames[ctx.action]（action => 守卫名单，构造时传入）
  │
  ├─ 名单非空 → GuardChain::run(ctx)  （按 priority 升序；首 block 短路）
  │     ├─ 未通过 → FlowResult::blocked(errorCode, detail, warnings)   ← 直接返回
  │     └─ 通过   → 收集 warnings
  │
  ├─ ActionRegistry::get(ctx.action)->handle(ctx)   ← 业务动作（写数量/位置/日志）
  │
  ├─ 合并守卫 warnings 进 result
  └─ 返回 FlowResult（宿主据此落库；事件已在 EventBus 入队，提交后 flush）
```

### rollback / jump / replay（回退 / 跳转 / 重放）

```
Engine::rollback(ctx, policy)   (= jump / replay 复用同链路)
  │
  ├─ policy->can(ctx, reason) 为 false → FlowResult::blocked('mes_flow_rollback_denied')
  ├─ publish FLOW_EVENT::ROLLBACK_BEFORE
  ├─ policy->compensate(ctx)   ← 令牌/任务/日志/计数补偿（宿主单事务内调用）
  ├─ publish FLOW_EVENT::ROLLBACK_AFTER(target)
  └─ 返回 FlowResult
```

> 注意：`jump()` / `replay()` 在 `Engine` 中直接委托给 `rollback()`，区别只在于传入的 `RollbackPolicyInterface::mode()`（`jump` / `replay` / `step_back` / `rework` / `reversal` / `cancel`）。

## 纯函数约束

`TransitionResolver`、`FlowTransitionResolver`、`ConditionEvaluator`、`FlowGraph` 校验等**纯算法**不触碰 IO、不读库、不发事件。宿主负责：

- 把存储态「快照」成 `RouteGraph` / `FlowGraph`（通过 `RouteGraphParser::fromSnapshot` 或 `FlowGraphParser::parse`）；
- 拿到 `FlowResult` 后，在**单事务**内完成持久化与补偿；
- 事务提交后调用 `EventBus::flush()`（失败 `clear()`）。

这样的分层让内核可单测、可影子比对、可灰度。
