<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\StateException;
use madong\stepcore\model\FlowTask;
use madong\stepcore\state\FlowStateMachine;
use PHPUnit\Framework\TestCase;

final class FlowStateMachineTest extends TestCase
{
    public function testForwardTaskTransitions(): void
    {
        $sm = new FlowStateMachine();
        self::assertTrue($sm->can('task', FlowTask::PENDING, FlowTask::RUNNING));
        self::assertTrue($sm->can('task', FlowTask::RUNNING, FlowTask::FINISHED));
        self::assertFalse($sm->can('task', FlowTask::FINISHED, FlowTask::RUNNING));
        $sm->ensure('task', FlowTask::RUNNING, FlowTask::FINISHED, 'completeTask');
        self::assertTrue(true);
    }

    public function testReverseWhitelistRequiresAuthorization(): void
    {
        $sm = new FlowStateMachine();
        self::assertTrue($sm->canReverse('task', FlowTask::FINISHED, FlowTask::RUNNING));
        $sm->ensureReverse('task', FlowTask::FINISHED, FlowTask::RUNNING, true);
        self::assertTrue(true);

        $this->expectException(StateException::class);
        $sm->ensureReverse('task', FlowTask::FINISHED, FlowTask::RUNNING, false);
    }

    public function testTerminalInstanceCannotBeReopenedViaForwardRule(): void
    {
        $sm = new FlowStateMachine();
        self::assertFalse($sm->can('instance', FlowStateMachine::INSTANCE_FINISHED, FlowStateMachine::INSTANCE_RUNNING));
        // 终态实例在策略层已拒绝；状态层的逆向白名单仅供未归档异常场景，测试其存在性
        self::assertTrue($sm->canReverse('instance', FlowStateMachine::INSTANCE_FINISHED, FlowStateMachine::INSTANCE_RUNNING));
    }

    public function testUnknownObjectIsDenied(): void
    {
        $sm = new FlowStateMachine();
        self::assertFalse($sm->can('unknown', 1, 2));
        $this->expectException(StateException::class);
        $sm->ensure('unknown', 1, 2);
    }
}
