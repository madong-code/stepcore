# StepCore · 工序执行内核

`madong/stepcore` 是一个**通用流程引擎包**（PHP ≥ 8.1，零运行时依赖，不依赖 Webman / 数据库 / 任何宿主框架）。它把「工序流转」的纯算法与「业务装配」彻底解耦：内核只做**路由解析、守卫链、动作执行、状态机、回退补偿、事件调度**，所有业务语义（工单、单元、WIP 卡、追溯、站荷、幂等……）都由宿主通过接口实现注入。

> 本文档是**库级**文档，描述 `stepcore` 自身的公共 API 与扩展点。宿主侧如何装配（mes 的 `MesKernel`、`config/engine.php`、守卫实现清单）见宿主 `download/mes/resource/doc/06-过站引擎核心.md`，二者互补、不重叠。

## 它解决什么

- **统一的过站/转序/返工/回退入口**：`Engine::flow()` + `Engine::rollback()`(jump/replay 复用)。
- **可插拔、可灰度**：动作、守卫、节点类型、条件函数、分支策略、回退策略、切面、事件监听器、模块全部通过「扩展注册表」注册，引擎零改动即可扩展。
- **确定性**：同种子（如 SN）恒定走同一分支；图 hash 规范化便于影子比对与版本校验。
- **事务安全**：事件先入队，`flush()` 在事务提交后发出；回滚时 `clear()`，不产生幽灵事件。
- **fail-fast**：配置错误（重复 seq、悬空边、未知条件键、未注册守卫）一律抛 `ConfigurationException`，不静默。

## 安装

```bash
composer require madong/stepcore
```

包通过 PSR-4 自动加载：`madong\stepcore\` → `src/`。

## 30 秒上手

```php
use madong\stepcore\Engine;
use madong\stepcore\action\ActionRegistry;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\event\EventBus;
use madong\stepcore\model\FlowContext;

// 1) 装配守卫链、动作、事件总线（宿主注入实现）
$guards  = new GuardRegistry();
$guards->register(new IdempotencyGuard());   // 实现 madong\stepcore\contracts\GuardInterface
$guards->register(new WoGuard());
$actions = new ActionRegistry();
$actions->register(new PassAction());        // 实现 madong\stepcore\contracts\ActionInterface
$events  = new EventBus($publisher);         // 实现 madong\stepcore\interface\services\IEventPublisher

// 2) 构造引擎：action => 守卫名单
$engine = new Engine($guards, $actions, $events, [
    'pass' => ['idempotency', 'wo'],
]);

// 3) 执行一次过站
$ctx    = new FlowContext(action: 'pass', nodeKey: 'nd_smt', instanceId: 'i_1', unitNo: 'SN123');
$result = $engine->flow($ctx);

// 4) 事务提交后 flush 事件
$events->flush();
```

## 核心概念一览

| 概念 | 所在 | 职责 |
|------|------|------|
| `Engine` | `src/Engine.php` | 统一编排入口（flow / rollback / jump / replay） |
| `FlowContext` | `model/` | 一次动作的全部输入（值对象，`with()` 派生副本） |
| `FlowResult` | `model/` | 统一返回结构，含 `toLegacyArray()` 兼容旧接口 |
| `FlowGraph` / `FlowNode` / `FlowEdge` | `model/` | **v2 编译图**：node_key 定位、出边 O(出边数) |
| `RouteGraph` / `RouteNode` / `RouteEdge` | `model/` | **v1 路线图**：seq 定位 + 隐式顺延（兼容/回填用） |
| `TransitionResolver` / `FlowTransitionResolver` | `route/` | 变迁路由解析（v1 / v2） |
| `GuardChain` / `GuardRegistry` | `guard/` | 守卫链（短路 / 聚合） |
| `ActionRegistry` / `ActionInterface` | `action/` | 动作处理器 |
| `RollbackPolicy` / `RollbackContext` / `RollbackResolver` | `rollback/` | 六类回退模式 |
| `FlowStateMachine` / `StateMachine` | `state/` | 状态迁移白名单 |
| `ExtensionRegistry` | `registry/` | 统一扩展注册表（manifest 驱动） |
| `EventBus` / `FlowEventBus` / `FlowEvent` | `event/` | 事件模型与事务后总线 |
| `FlowGraphParser` / `RouteGraphParser` / `ConditionEvaluator` | `parser/` | 解析与条件 DSL 求值 |

## 文档导航

| 文档 | 内容 |
|------|------|
| [01-architecture.md](01-architecture.md) | 分层架构、请求生命周期、目录映射、依赖方向 |
| [02-data-model.md](02-data-model.md) | 全部数据模型（图 / 上下文 / 结果）字段契约 |
| [03-routing.md](03-routing.md) | 变迁解析、分支策略、条件 DSL |
| [04-guards.md](04-guards.md) | 守卫链语义与契约 |
| [05-actions.md](05-actions.md) | 动作注册与契约 |
| [06-rollback.md](06-rollback.md) | 回退六模式、上下文、解析器、状态机兜底 |
| [07-state-machine.md](07-state-machine.md) | 通用状态机 |
| [08-extensions.md](08-extensions.md) | 扩展注册表与全部扩展契约（manifest） |
| [09-events.md](09-events.md) | 事件模型与两种总线 |
| [10-parsing.md](10-parsing.md) | 图解析、v1→v2 转换、graph_hash |
| [11-exceptions.md](11-exceptions.md) | 异常层次 |
| [12-integration.md](12-integration.md) | 宿主装配范式（通用） |
| [13-ui-designer.md](13-ui-designer.md) | UI 设计器：iframe 接入方式 + 基本操作 |

## 演示与工具

- **可运行 demo**：`demo/`（PHP 内置服务器或 php-fpm 均可，见 `demo/README.md`），宿主页以 iframe 嵌入设计器并联动引擎解析。
- **UI 设计器**：`ui/`（基于 LogicFlow，UMD 包本地内置于 `ui/vendor/`，HTML `<script>` 直接引入、无构建无联网，可独立以 iframe 远程嵌入）。

## 设计原则

1. **算法在包内，业务在宿主**：内核不含任何业务判断，条件 DSL 走白名单函数（`ConditionFn`），核心不做业务。
2. **依赖单向**：宿主 → 包；包内自带 `enum\TransitionType`、`model\*`，不反向依赖宿主枚举。
3. **值对象不可变**：`FlowContext`/`FlowToken`/`FlowTask`/`FlowResult` 均 `readonly` + `with()` 派生，无共享可变状态。
4. **路线图双轨**：`RouteGraph`（seq 索引 + 隐式顺延）与 `FlowGraph`（node_key 显式图）并存，切流完成前互不替代；v1 仅用于兼容读取与一次性回填。
