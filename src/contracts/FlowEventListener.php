<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\event\FlowEvent;

/** 事件订阅；事件在事务提交后 flush，不承担归档正确性。 */
interface FlowEventListener
{
    public function onEvent(FlowEvent $event): void;
}
