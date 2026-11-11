<?php
declare(strict_types=1);

namespace madong\stepcore\registry;

use madong\stepcore\contracts\ActionInterface;
use madong\stepcore\contracts\AspectInterface;
use madong\stepcore\contracts\BranchStrategyInterface;
use madong\stepcore\contracts\ConditionFn;
use madong\stepcore\contracts\FlowEventListener;
use madong\stepcore\contracts\FlowModule;
use madong\stepcore\contracts\GuardInterface;
use madong\stepcore\contracts\NodeTypeHandler;
use madong\stepcore\contracts\RollbackPolicyInterface;
use madong\stepcore\exception\ConfigurationException;

/**
 * 统一扩展注册表：扫描各插件 config/flow_extension.php 的 manifest 并实例化注册。
 * 纯内核，不依赖 Webman / 数据库；宿主负责扫描目录并传入 manifest 数组。
 */
final class ExtensionRegistry
{
    private NodeTypeRegistry $nodeTypes;

    /** @var array<string, ConditionFn> */
    private array $conditions = [];
    /** @var array<string, GuardInterface> */
    private array $guards = [];
    /** @var array<string, ActionInterface> */
    private array $actions = [];
    /** @var array<string, RollbackPolicyInterface> */
    private array $rollbackPolicies = [];
    /** @var array<string, BranchStrategyInterface> */
    private array $branchStrategies = [];
    /** @var array<string, FlowEventListener> */
    private array $listeners = [];
    /** @var list<AspectInterface> */
    private array $aspects = [];
    /** @var list<FlowModule> */
    private array $modules = [];

    public function __construct()
    {
        $this->nodeTypes = new NodeTypeRegistry();
    }

    public function nodeTypes(): NodeTypeRegistry
    {
        return $this->nodeTypes;
    }

    /** @param list<array<string, mixed>> $manifests 每个插件的 flow_extension.php 返回数组 */
    public function discover(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            foreach ((array) ($manifest['node_types'] ?? []) as $code => $decl) {
                $this->nodeTypes->register(
                    (string) $code,
                    $this->resolveSchema($decl['property_schema'] ?? []),
                    $this->make($decl['handler'] ?? null, NodeTypeHandler::class),
                    array_diff_key((array) $decl, ['property_schema' => 1, 'handler' => 1]),
                );
            }
            $this->registerAll($manifest['conditions'] ?? [], 'conditions');
            $this->registerAll($manifest['guards'] ?? [], 'guards');
            $this->registerAll($manifest['actions'] ?? [], 'actions');
            $this->registerAll($manifest['rollback_policies'] ?? [], 'rollbackPolicies');
            $this->registerAll($manifest['branch_strategies'] ?? [], 'branchStrategies');
            $this->registerAll($manifest['listeners'] ?? [], 'listeners');
            $this->registerAll($manifest['aspects'] ?? [], 'aspects');
            foreach ((array) ($manifest['modules'] ?? []) as $module) {
                $this->modules[] = $this->make($module, FlowModule::class);
            }
        }
        usort($this->aspects, fn (AspectInterface $a, AspectInterface $b) => $a->priority() <=> $b->priority());
    }

    /** @param array<string, mixed> $decl */
    private function registerAll(array $decl, string $bucket): void
    {
        foreach ($decl as $key => $class) {
            $name = is_string($key) ? $key : (string) $class;
            $instance = match ($bucket) {
                'conditions'        => $this->make($class, ConditionFn::class),
                'guards'            => $this->make($class, GuardInterface::class),
                'actions'           => $this->make($class, ActionInterface::class),
                'rollbackPolicies'  => $this->make($class, RollbackPolicyInterface::class),
                'branchStrategies'  => $this->make($class, BranchStrategyInterface::class),
                'listeners'         => $this->make($class, FlowEventListener::class),
                'aspects'           => $this->make($class, AspectInterface::class),
                default             => throw new ConfigurationException('mes_flow_extension_bucket_unknown'),
            };
            switch ($bucket) {
                case 'conditions':
                    $this->conditions[$name] = $instance;
                    break;
                case 'guards':
                    $this->guards[$name] = $instance;
                    break;
                case 'actions':
                    $this->actions[$name] = $instance;
                    break;
                case 'rollbackPolicies':
                    $this->rollbackPolicies[$name] = $instance;
                    break;
                case 'branchStrategies':
                    $this->branchStrategies[$name] = $instance;
                    break;
                case 'listeners':
                    $this->listeners[$name] = $instance;
                    break;
                case 'aspects':
                    $this->aspects[] = $instance;
                    break;
            }
        }
    }

    private function make(mixed $class, string $contract): object
    {
        if ($class instanceof $contract) {
            return $class;
        }
        if (!is_string($class) || !class_exists($class)) {
            throw new ConfigurationException('mes_flow_extension_class_missing:' . (string) $class);
        }
        $instance = new $class();
        if (!$instance instanceof $contract) {
            throw new ConfigurationException('mes_flow_extension_contract_mismatch:' . $class);
        }
        return $instance;
    }

    /** @return array<string, mixed> */
    private function resolveSchema(mixed $decl): array
    {
        if (is_array($decl)) {
            return $decl;
        }
        if (is_string($decl) && class_exists($decl)) {
            $obj = new $decl();
            return method_exists($obj, 'schema') ? (array) $obj->schema() : [];
        }
        return [];
    }

    /** @return array<string, ConditionFn> */
    public function conditions(): array { return $this->conditions; }
    /** @return array<string, GuardInterface> */
    public function guards(): array { return $this->guards; }
    /** @return array<string, ActionInterface> */
    public function actions(): array { return $this->actions; }
    /** @return array<string, RollbackPolicyInterface> */
    public function rollbackPolicies(): array { return $this->rollbackPolicies; }
    /** @return array<string, BranchStrategyInterface> */
    public function branchStrategies(): array { return $this->branchStrategies; }
    /** @return array<string, FlowEventListener> */
    public function listeners(): array { return $this->listeners; }
    /** @return list<AspectInterface> */
    public function aspects(): array { return $this->aspects; }
    /** @return list<FlowModule> */
    public function modules(): array { return $this->modules; }
}
