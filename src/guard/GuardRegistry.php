<?php
declare(strict_types=1);

namespace madong\stepcore\guard;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowContext;

/**
 * 守卫注册表：命名注册 + 链构建 + 启动期 fail-fast 校验（GC-006）。
 */
final class GuardRegistry
{
    /** @var array<string, GuardInterface> */
    private array $guards = [];

    public function register(GuardInterface $guard): void
    {
        $this->guards[$guard->name()] = $guard;
    }

    public function has(string $name): bool
    {
        return isset($this->guards[$name]);
    }

    public function get(string $name): ?GuardInterface
    {
        return $this->guards[$name] ?? null;
    }

    /** @return array<string, GuardInterface> */
    public function all(): array
    {
        return $this->guards;
    }

    /**
     * 按给定名单构建守卫链（顺序与名单无关，链内按 priority 排）。
     * @param list<string> $names
     */
    public function chain(array $names): GuardChain
    {
        $this->assertRegistered($names);
        return new GuardChain(array_map(fn (string $n) => $this->guards[$n], $names));
    }

    /** @param list<string> $names */
    public function assertRegistered(array $names): void
    {
        $missing = array_values(array_filter($names, fn (string $n) => !isset($this->guards[$n])));
        if ($missing !== []) {
            throw new ConfigurationException('mes_flow_guard_not_registered:' . implode(',', $missing));
        }
    }
}
