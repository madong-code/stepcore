# StepCore · Process Execution Kernel

`madong/stepcore` is a **generic process-engine library** (PHP ≥ 8.1, zero runtime dependencies, framework-agnostic; does not depend on Webman / database / any host framework). It cleanly decouples the pure algorithms of "process flow" from "business wiring": the kernel only performs **route resolution, guard chains, action execution, state machines, rollback compensation, and event dispatch**. All business semantics (work orders, units, WIP cards, traceability, station load, idempotency, …) are injected by the host via interface implementations.

> This document is a **library-level** document describing `stepcore`'s own public API and extension points. How the host wires it up is covered in host-specific docs; the two complement each other without overlap.

## What it solves

- **Unified entry for pass / transfer / rework / rollback**: `Engine::flow()` + `Engine::rollback()` (jump / replay share the same pipeline).
- **Pluggable & canary-friendly**: actions, guards, node types, condition functions, branch strategies, rollback policies, aspects, event listeners, and modules are all registered through the "extension registry" — the engine extends with zero code changes.
- **Deterministic**: the same seed (e.g. SN) always takes the same branch; graph hashes are normalized for shadow comparison and version verification.
- **Transaction-safe**: events are enqueued first and `flush()`-ed only after the transaction commits; on rollback `clear()` is called, so no ghost events are emitted.
- **Fail-fast**: configuration errors (duplicate seq, dangling edges, unknown condition keys, unregistered guards) always throw `ConfigurationException` — never silently ignored.

## Installation

```bash
composer require madong/stepcore
```

The package is auto-loaded via PSR-4: `madong\stepcore\` → `src/`.

## 30-second quick start

```php
use madong\stepcore\Engine;
use madong\stepcore\action\ActionRegistry;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\event\EventBus;
use madong\stepcore\model\FlowContext;

// 1) Wire up guard chain, actions, and event bus (host injects implementations)
$guards  = new GuardRegistry();
$guards->register(new IdempotencyGuard());   // implements madong\stepcore\contracts\GuardInterface
$guards->register(new WoGuard());
$actions = new ActionRegistry();
$actions->register(new PassAction());        // implements madong\stepcore\contracts\ActionInterface
$events  = new EventBus($publisher);         // implements madong\stepcore\interface\services\IEventPublisher

// 2) Construct the engine: action => guard names
$engine = new Engine($guards, $actions, $events, [
    'pass' => ['idempotency', 'wo'],
]);

// 3) Execute one pass-through
$ctx    = new FlowContext(action: 'pass', nodeKey: 'nd_smt', instanceId: 'i_1', unitNo: 'SN123');
$result = $engine->flow($ctx);

// 4) flush events after the transaction commits
$events->flush();
```

## Core concepts at a glance

| Concept | Location | Responsibility |
|---------|----------|----------------|
| `Engine` | `src/Engine.php` | Unified orchestration entry (flow / rollback / jump / replay) |
| `FlowContext` | `model/` | All inputs for one action (value object, derived via `with()`) |
| `FlowResult` | `model/` | Unified return structure, with `toLegacyArray()` for legacy compatibility |
| `FlowGraph` / `FlowNode` / `FlowEdge` | `model/` | **v2 compiled graph**: node_key lookup, out-edges O(degree) |
| `RouteGraph` / `RouteNode` / `RouteEdge` | `model/` | **v1 route graph**: seq lookup + implicit continuation (compat / backfill) |
| `TransitionResolver` / `FlowTransitionResolver` | `route/` | Transition route resolution (v1 / v2) |
| `GuardChain` / `GuardRegistry` | `guard/` | Guard chain (short-circuit / aggregate) |
| `ActionRegistry` / `ActionInterface` | `action/` | Action handlers |
| `RollbackPolicy` / `RollbackContext` / `RollbackResolver` | `rollback/` | Six rollback modes |
| `FlowStateMachine` / `StateMachine` | `state/` | State transition whitelist |
| `ExtensionRegistry` | `registry/` | Unified extension registry (manifest-driven) |
| `EventBus` / `FlowEventBus` / `FlowEvent` | `event/` | Event model & post-transaction bus |
| `FlowGraphParser` / `RouteGraphParser` / `ConditionEvaluator` | `parser/` | Parsing & condition DSL evaluation |

## Design principles

1. **Algorithm in the package, business in the host**: the kernel contains no business decisions; the condition DSL uses a whitelist of functions (`ConditionFn`), the core stays business-free.
2. **One-way dependency**: host → package; the package ships its own `enum\TransitionType` and `model\*`, never depending back on host enums.
3. **Immutable value objects**: `FlowContext` / `FlowToken` / `FlowTask` / `FlowResult` are all `readonly` and derived via `with()`, with no shared mutable state.
4. **Dual route-graph tracks**: `RouteGraph` (seq-indexed + implicit continuation) and `FlowGraph` (explicit node_key graph) coexist until migration is complete; v1 is only for compatible reads and one-time backfill.

## Directory layout

```
packages/stepcore/
├── src/          # Kernel source (Engine + model/contracts/action/guard/route/rollback/state/parser/registry/lifecycle/aop/event/interface/schema/exception)
├── docs/         # Library docs (architecture / data-model / routing / guards / actions / rollback / state-machine / extensions / events / parsing / exceptions / integration / UI designer)
├── ui/           # UI designer (LogicFlow-based, local UMD bundle, iframe-embeddable)
├── demo/         # Runnable demo (PHP built-in server or php-fpm)
└── tests/        # PHPUnit unit & integration tests
```

## Documentation index

| Doc | Contents |
|-----|----------|
| `docs/01-architecture.md` | Layered architecture, request lifecycle, directory mapping, dependency direction |
| `docs/02-data-model.md` | All data models (graph / context / result) field contracts |
| `docs/03-routing.md` | Transition resolution, branch strategies, condition DSL |
| `docs/04-guards.md` | Guard chain semantics & contracts |
| `docs/05-actions.md` | Action registration & contracts |
| `docs/06-rollback.md` | Six rollback modes, context, resolver, state-machine fallback |
| `docs/07-state-machine.md` | Generic state machine |
| `docs/08-extensions.md` | Extension registry & all extension contracts (manifest) |
| `docs/09-events.md` | Event model & two buses |
| `docs/10-parsing.md` | Graph parsing, v1→v2 conversion, graph_hash |
| `docs/11-exceptions.md` | Exception hierarchy |
| `docs/12-integration.md` | Generic host wiring patterns |
| `docs/13-ui-designer.md` | UI designer: iframe integration & basic operations |

## Demo & tooling

- **Runnable demo**: `demo/` (works with the PHP built-in server or php-fpm; see `demo/README.md`). The host page embeds the designer in an iframe and links it to the engine parser.
- **UI designer**: `ui/` (LogicFlow-based; the UMD bundle is vendored locally under `ui/vendor/`, loaded directly via an HTML `<script>` with no build step and no network — can be embedded remotely as an independent iframe).

## License

MIT © madong
