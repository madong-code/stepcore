<?php
declare(strict_types=1);

namespace madong\stepcore\aop;

use madong\stepcore\contracts\AspectInterface;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;

/** 优先级切面链：BEFORE → 业务 → AFTER，异常走 onError；shouldProceed=false 可中断。 */
final class AspectDispatcher
{
    /** @var list<AspectInterface> 按 priority 升序 */
    private array $aspects = [];

    public function add(AspectInterface $aspect): void
    {
        $this->aspects[] = $aspect;
        usort($this->aspects, fn (AspectInterface $a, AspectInterface $b) => $a->priority() <=> $b->priority());
    }

    /** @param list<AspectInterface> $aspects */
    public function addMany(array $aspects): void
    {
        foreach ($aspects as $aspect) {
            $this->add($aspect);
        }
    }

    /** false 表示应中断后续评估（对齐 workflow shouldProceed 语义） */
    public function before(FlowContext $ctx): bool
    {
        foreach ($this->aspects as $aspect) {
            if (!$aspect->before($ctx)) {
                return false;
            }
        }
        return true;
    }

    public function after(FlowContext $ctx, FlowResult $result): void
    {
        foreach ($this->aspects as $aspect) {
            $aspect->after($ctx, $result);
        }
    }

    public function onError(FlowContext $ctx, \Throwable $e): void
    {
        foreach ($this->aspects as $aspect) {
            $aspect->onError($ctx, $e);
        }
    }

    public function count(): int
    {
        return count($this->aspects);
    }
}
