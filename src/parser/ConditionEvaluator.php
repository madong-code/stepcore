<?php
declare(strict_types=1);

namespace madong\stepcore\parser;

use madong\stepcore\contracts\ConditionFn;
use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowEdge;

/**
 * 条件求值统一入口：白名单 rules + 受限表达式（借鉴 workflow TransitionModel.expr）。
 * 表达式只允许 #变量 / 字面量 / 比较与逻辑运算 / 括号，禁止函数调用与任意代码。
 */
final class ConditionEvaluator
{
    /** @var array<string, ConditionFn> */
    private array $functions = [];

    public function register(ConditionFn $fn): void
    {
        $this->functions[$fn->key()] = $fn;
    }

    public function matches(FlowEdge $edge, FlowContext $ctx): bool
    {
        $condition = $edge->condition();
        foreach ($condition['rules'] as $rule) {
            if (!is_array($rule) || !$this->rule($rule, $ctx)) {
                return false;
            }
        }
        $expr = $condition['expr'];
        if ($expr === null || trim((string) $expr) === '') {
            return true;
        }
        return $this->expr((string) $expr, $ctx);
    }

    /** @param array<string, mixed> $rule */
    private function rule(array $rule, FlowContext $ctx): bool
    {
        $key = (string) ($rule['key'] ?? '');
        $op  = (string) ($rule['op'] ?? 'eq');
        $expected = $rule['value'] ?? null;
        if (isset($this->functions[$key])) {
            $actual = $this->functions[$key]->evaluate($ctx, (array) ($rule['args'] ?? []));
        } else {
            $actual = $this->variable($key, $ctx);
        }
        return $this->compare($actual, $op, $expected);
    }

    /** 表达式求值（公开便于单测） */
    public function expr(string $expression, FlowContext $ctx): bool
    {
        $tokens = $this->tokenize($expression);
        if ($tokens === []) {
            throw new ConfigurationException('mes_flow_condition_invalid:' . $expression);
        }
        $pos = 0;
        $value = $this->parseOr($tokens, $pos, $ctx);
        if ($pos !== count($tokens)) {
            throw new ConfigurationException('mes_flow_condition_invalid:' . $expression);
        }
        return (bool) $value;
    }

    /** @return list<string> */
    private function tokenize(string $expr): array
    {
        $pattern = '/\s*(&&|\|\||>=|<=|==|!=|[><!()]|#?[A-Za-z_][A-Za-z0-9_.]*|-?\d+(?:\.\d+)?|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/';
        if (preg_match_all($pattern, $expr, $m) === false || $m[0] === []) {
            return [];
        }
        $tokens = array_map('trim', $m[0]);
        if (implode('', $tokens) !== preg_replace('/\s+/', '', $expr)) {
            throw new ConfigurationException('mes_flow_condition_invalid:' . $expr);
        }
        return $tokens;
    }

    /** @param list<string> $t */
    private function parseOr(array $t, int &$i, FlowContext $ctx): bool
    {
        $value = $this->parseAnd($t, $i, $ctx);
        while (($t[$i] ?? null) === '||') {
            $i++;
            $right = $this->parseAnd($t, $i, $ctx);
            $value = $value || $right;
        }
        return $value;
    }

    /** @param list<string> $t */
    private function parseAnd(array $t, int &$i, FlowContext $ctx): bool
    {
        $value = $this->parseComparison($t, $i, $ctx);
        while (($t[$i] ?? null) === '&&') {
            $i++;
            $right = $this->parseComparison($t, $i, $ctx);
            $value = $value && $right;
        }
        return $value;
    }

    /** @param list<string> $t */
    private function parseComparison(array $t, int &$i, FlowContext $ctx): bool
    {
        $left = $this->parseUnary($t, $i, $ctx);
        $op = $t[$i] ?? null;
        if (is_string($op) && in_array($op, ['>', '>=', '<', '<=', '==', '!='], true)) {
            $i++;
            $right = $this->parseUnary($t, $i, $ctx);
            return $this->compare($left, $op, $right);
        }
        return (bool) $left;
    }

    /** @param list<string> $t */
    private function parseUnary(array $t, int &$i, FlowContext $ctx): mixed
    {
        $token = $t[$i] ?? null;
        if ($token === '!') {
            $i++;
            return !$this->parseUnary($t, $i, $ctx);
        }
        if ($token === '-') {
            $i++;
            return -$this->parseUnary($t, $i, $ctx);
        }
        return $this->parsePrimary($t, $i, $ctx);
    }

    /** @param list<string> $t */
    private function parsePrimary(array $t, int &$i, FlowContext $ctx): mixed
    {
        $token = $t[$i] ?? null;
        if ($token === null) {
            throw new ConfigurationException('mes_flow_condition_invalid');
        }
        $i++;
        if ($token === '(') {
            $value = $this->parseOr($t, $i, $ctx);
            if (($t[$i] ?? null) !== ')') {
                throw new ConfigurationException('mes_flow_condition_invalid:missing_paren');
            }
            $i++;
            return $value;
        }
        if ($token === 'true') {
            return true;
        }
        if ($token === 'false') {
            return false;
        }
        if ($token === 'null') {
            return null;
        }
        if (is_numeric($token)) {
            return str_contains($token, '.') ? (float) $token : (int) $token;
        }
        if ($token !== '' && ($token[0] === "'" || $token[0] === '"')) {
            return substr($token, 1, -1);
        }
        return $this->variable(ltrim($token, '#'), $ctx);
    }

    private function variable(string $path, FlowContext $ctx): mixed
    {
        $sources = [
            $ctx->variable,
            $ctx->properties,
            $ctx->unit,
            $ctx->card,
            $ctx->extra,
        ];
        $segments = explode('.', $path);
        foreach ($sources as $source) {
            $value = $source;
            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function compare(mixed $left, string $op, mixed $right): bool
    {
        return match ($op) {
            '>', 'gt'   => $left > $right,
            '>=', 'gte' => $left >= $right,
            '<', 'lt'   => $left < $right,
            '<=', 'lte' => $left <= $right,
            '!=', 'ne'  => $left != $right,
            'in'        => is_array($right) && (is_array($left)
                ? array_intersect($left, $right) !== []
                : in_array($left, $right, false)),
            'not_in'    => is_array($right) && (is_array($left)
                ? array_intersect($left, $right) === []
                : !in_array($left, $right, false)),
            'eq', '=='  => $left == $right,
            default     => throw new ConfigurationException('mes_flow_condition_invalid_op:' . $op),
        };
    }
}
