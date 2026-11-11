<?php
declare(strict_types=1);

namespace madong\stepcore\action;

use madong\stepcore\exception\ConfigurationException;

/**
 * 动作注册表（P3-06）。
 */
final class ActionRegistry
{
    /** @var array<string, ActionInterface> */
    private array $actions = [];

    public function register(ActionInterface $action): void
    {
        $this->actions[$action->name()] = $action;
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    public function get(string $name): ActionInterface
    {
        return $this->actions[$name] ?? throw new ConfigurationException('mes_flow_action_not_registered:' . $name);
    }

    /** @return array<string, ActionInterface> */
    public function all(): array
    {
        return $this->actions;
    }
}
