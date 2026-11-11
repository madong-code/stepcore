<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\parser\ConditionEvaluator;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase
{
    public function testExpressionWithVariables(): void
    {
        $eval = new ConditionEvaluator();
        $ctx = new FlowContext(action: 'pass', variable: ['qty_ng' => 2, 'yield' => 0.9]);
        self::assertTrue($eval->expr('#qty_ng > 0 && #yield < 0.95', $ctx));
        self::assertFalse($eval->expr('#qty_ng > 0 && #yield > 0.95', $ctx));
        self::assertTrue($eval->expr('(#qty_ng == 2 || #yield > 1) && !(#yield >= 1)', $ctx));
    }

    public function testConditionRulesUseWhitelistAndContext(): void
    {
        $eval = new ConditionEvaluator();
        $ctx = new FlowContext(action: 'pass', properties: ['scan_mode' => 2], extra: ['defect' => ['D01']]);
        $edge = new FlowEdge('ed_1', 'nd_a', 'nd_b', 'straight', [
            'condition' => [
                'expr' => null,
                'rules' => [
                    ['key' => 'scan_mode', 'op' => 'eq', 'value' => 2],
                    ['key' => 'defect', 'op' => 'in', 'value' => ['D01', 'D02']],
                ],
            ],
        ]);
        self::assertTrue($eval->matches($edge, $ctx));
        $edge2 = new FlowEdge('ed_2', 'nd_a', 'nd_b', 'straight', [
            'condition' => ['expr' => null, 'rules' => [['key' => 'scan_mode', 'op' => 'eq', 'value' => 9]]],
        ]);
        self::assertFalse($eval->matches($edge2, $ctx));
    }

    public function testEmptyConditionPasses(): void
    {
        $eval = new ConditionEvaluator();
        $edge = new FlowEdge('ed_1', 'nd_a', 'nd_b', 'straight', []);
        self::assertTrue($eval->matches($edge, new FlowContext(action: 'pass')));
    }

    public function testIllegalExpressionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConditionEvaluator())->expr('#a > 0 && system("rm")', new FlowContext(action: 'pass'));
    }
}
