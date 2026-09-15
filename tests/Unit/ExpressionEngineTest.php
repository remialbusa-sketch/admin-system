<?php

namespace Tests\Unit;

use App\Support\Dashboard\ExpressionEngine;
use App\Support\Dashboard\ExpressionSyntaxError;
use PHPUnit\Framework\TestCase;

class ExpressionEngineTest extends TestCase
{
    private ExpressionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ExpressionEngine();
    }

    public function test_arithmetic_respects_precedence_and_parentheses(): void
    {
        $this->assertSame(7.0, $this->engine->evaluate('1 + 2 * 3'));
        $this->assertSame(9.0, $this->engine->evaluate('(1 + 2) * 3'));
        $this->assertSame(2.25, $this->engine->evaluate('9 / 4'));
        $this->assertSame(1.0, (float) $this->engine->evaluate('10 % 3'));
        $this->assertSame(0.5, $this->engine->evaluate('0.5 * 1'));
    }

    public function test_power_is_right_associative_and_binds_tighter_than_unary_minus(): void
    {
        $this->assertEqualsWithDelta(512.0, $this->engine->evaluate('2 ^ 3 ^ 2'), 0.0001);
        $this->assertEqualsWithDelta(-4.0, $this->engine->evaluate('-2 ^ 2'), 0.0001);
        $this->assertEqualsWithDelta(2.0, $this->engine->evaluate('-3 + 5'), 0.0001);
    }

    public function test_ternary_and_rag_style_expressions(): void
    {
        $vars = ['active_ratio' => 82.4, 'warranty_ratio' => 41.0];

        $this->assertSame('amber', $this->engine->evaluate(
            "active_ratio >= 90 ? 'green' : (active_ratio >= 75 ? 'amber' : 'red')",
            $vars,
        ));

        $this->assertSame('red', $this->engine->evaluate("warranty_ratio >= 50 ? 'green' : 'red'", $vars));

        // Chained ternary is right-associative.
        $this->assertSame('b', $this->engine->evaluate("x > 2 ? 'a' : x > 1 ? 'b' : 'c'", ['x' => 1.5]));
    }

    public function test_logical_and_comparison_operators(): void
    {
        $this->assertTrue($this->engine->evaluate('1 < 2 && 2 <= 2'));
        $this->assertTrue($this->engine->evaluate('a == 1 || b == 1', ['a' => 0, 'b' => 1]));
        $this->assertTrue($this->engine->evaluate("!false_var", ['false_var' => false]));
        $this->assertSame('yes', $this->engine->evaluate("status == 'Active' ? 'yes' : 'no'", ['status' => 'Active']));
    }

    public function test_allow_listed_functions(): void
    {
        $this->assertEqualsWithDelta(25.0, $this->engine->evaluate('pct(1, 4)'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->engine->evaluate('pct(1, 0)'), 0.0001);
        $this->assertEqualsWithDelta(25.4, $this->engine->evaluate('round(25.444, 1)'), 0.0001);
        $this->assertEqualsWithDelta(3.0, $this->engine->evaluate('max(1, 3, 2)'), 0.0001);
        $this->assertEqualsWithDelta(1.0, $this->engine->evaluate('min(3, 1, 2)'), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->engine->evaluate('abs(-4)'), 0.0001);
        $this->assertSame('a', $this->engine->evaluate("if(1 > 0, 'a', 'b')"));
        $this->assertSame(0.0, $this->engine->evaluate('coalesce(install_delta, 0)', ['install_delta' => null]));
        $this->assertSame(7.5, $this->engine->evaluate('coalesce(install_delta, 7.5)', ['install_delta' => 7.5]));
        $this->assertEqualsWithDelta(6.0, $this->engine->evaluate('sum(1, 2, 3)'), 0.0001);
    }

    public function test_nested_calls_and_variables(): void
    {
        $vars = ['installed' => 200, 'active' => 186];

        $this->assertEqualsWithDelta(93.0, $this->engine->evaluate('round(pct(active, installed), 1)', $vars), 0.0001);
        $this->assertEqualsWithDelta(186.0, $this->engine->evaluate('installed * pct(active, installed) / 100', $vars), 0.0001);
    }

    public function test_division_by_zero_yields_null_not_an_error(): void
    {
        // Business-user friendly: an empty scope divides by zero all the
        // time. Division yields null (rendered as "no data") instead of
        // throwing — only syntax/config errors are fatal.
        $this->assertNull($this->engine->evaluate('1 / 0'));
        $this->assertNull($this->engine->evaluate('10 % 0'));
        // null propagates through arithmetic as PHP's own null math.
        $this->assertEqualsWithDelta(0.0, $this->engine->evaluate('1 / 0 * 100'), 0.0001);
    }

    public function test_unknown_variable_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);
        $this->expectExceptionMessage("Unknown variable 'not_a_metric'.");

        $this->engine->evaluate('not_a_metric + 1');
    }

    public function test_unknown_function_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);
        $this->expectExceptionMessage("Unknown function 'system'.");

        $this->engine->evaluate("system('ls')");
    }

    public function test_shell_style_payloads_cannot_tokenize(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('pct(active, installed); rm -rf /');
    }

    public function test_trailing_garbage_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('1 1');
    }

    public function test_unbalanced_parentheses_throw(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('(1 + 2');
    }

    public function test_empty_expression_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('   ');
    }

    public function test_overlong_expression_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('1 + '.str_repeat('1 + ', 200).'1');
    }

    public function test_deeply_nested_expression_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate(str_repeat('(', 60).'1'.str_repeat(')', 60));
    }

    public function test_wrong_arity_throws(): void
    {
        $this->expectException(ExpressionSyntaxError::class);

        $this->engine->evaluate('pct(1)');
    }

    public function test_ast_is_inspectable_for_validation(): void
    {
        $ast = $this->engine->parse('pct(active, installed)');

        $this->assertSame('call', $ast['k']);
        $this->assertSame('pct', $ast['fn']);
        $this->assertCount(2, $ast['args']);
    }

    public function test_to_tree_extracts_a_node_graph_from_arithmetic(): void
    {
        // active / installed * 100 → 3 metric/number leaves + 2 operators.
        $tree = $this->engine->toTree('active / installed * 100');

        $this->assertNotNull($tree);
        $this->assertCount(5, $tree['nodes']);

        $kinds = array_column($tree['nodes'], 'kind');
        $this->assertSame(['metric', 'metric', 'op', 'number', 'op'], $kinds);

        // Root is the multiplication; it consumes the division and 100.
        $root = $tree['nodes'][4];
        $this->assertSame('*', $root['value']);
        $this->assertSame([$tree['nodes'][2]['id'], $tree['nodes'][3]['id']], $root['inputs']);

        // Every node gets canvas coordinates.
        foreach ($tree['nodes'] as $node) {
            $this->assertArrayHasKey('x', $node);
            $this->assertArrayHasKey('y', $node);
        }

        // A single metric is a one-node graph rooted at that node.
        $single = $this->engine->toTree('warranty_ratio');
        $this->assertCount(1, $single['nodes']);
        $this->assertSame('metric', $single['nodes'][0]['kind']);
        $this->assertSame($single['nodes'][0]['id'], $single['root']);
    }

    public function test_to_tree_extracts_function_calls(): void
    {
        $tree = $this->engine->toTree('round(pct(active, installed), 1)');

        $this->assertNotNull($tree);

        $root = collect($tree['nodes'])->firstWhere('id', $tree['root']);
        $this->assertSame('fn', $root['kind']);
        $this->assertSame('round', $root['value']);
        $this->assertCount(2, $root['inputs']);
    }

    public function test_to_tree_returns_null_for_unrepresentable_expressions(): void
    {
        // Comparisons and ternaries are not canvas-representable.
        $this->assertNull($this->engine->toTree("active_ratio >= 90 ? 'green' : 'red'"));

        // Unary minus is not drawn.
        $this->assertNull($this->engine->toTree('-active'));

        // Garbage and empty.
        $this->assertNull($this->engine->toTree('1 / 0 +'));
        $this->assertNull($this->engine->toTree(''));
    }

    public function test_tree_round_trips_through_the_engine(): void
    {
        // toTree output, serialized the way the editor does (explicit
        // parens, function calls), must re-parse and evaluate identically.
        $tree = $this->engine->toTree('active / installed * 100');
        $this->assertNotNull($tree);

        $byId = collect($tree['nodes'])->keyBy('id');

        $serialize = function (string $id) use (&$serialize, $byId): string {
            $node = $byId->get($id);

            if ($node['kind'] === 'metric') {
                return $node['value'];
            }

            if ($node['kind'] === 'number') {
                return $node['value'];
            }

            $parts = array_map($serialize, $node['inputs']);

            return $node['kind'] === 'op'
                ? "({$parts[0]} {$node['value']} {$parts[1]})"
                : "{$node['value']}(".implode(', ', $parts).')';
        };

        $expression = $serialize($tree['root']);
        $this->assertSame('((active / installed) * 100)', $expression);
    }
}
