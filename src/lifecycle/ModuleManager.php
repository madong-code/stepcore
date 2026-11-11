<?php
declare(strict_types=1);

namespace madong\stepcore\lifecycle;

use madong\stepcore\contracts\FlowModule;
use madong\stepcore\registry\ExtensionRegistry;

/** 生命周期：discover（宿主扫描）→ register → boot → run → shutdown。 */
final class ModuleManager
{
    public function __construct(private readonly ExtensionRegistry $registry)
    {
    }

    public function add(FlowModule $module): void
    {
        // 追加由宿主直接注入的模块（不经 manifest）
        $this->extra[] = $module;
    }

    /** @var list<FlowModule> */
    private array $extra = [];
    private bool $booted = false;

    public function register(): void
    {
        foreach ([...$this->registry->modules(), ...$this->extra] as $module) {
            $module->register($this->registry);
        }
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        foreach ([...$this->registry->modules(), ...$this->extra] as $module) {
            $module->boot();
        }
        $this->booted = true;
    }

    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }
        foreach ([...$this->registry->modules(), ...$this->extra] as $module) {
            $module->shutdown();
        }
        $this->booted = false;
    }

    public function booted(): bool
    {
        return $this->booted;
    }
}
