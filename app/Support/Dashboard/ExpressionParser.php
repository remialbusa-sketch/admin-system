<?php

namespace App\Support\Dashboard;

/**
 * Pratt (precedence-climbing) parser for dashboard expressions.
 *
 * Binding powers, looser → tighter:
 *
 *   ternary        ? :        1   (right-assoc: a ? b : c ? d : e → a ? b : (c ? d : e))
 *   logical or     ||         2
 *   logical and    &&         3
 *   comparison     < > <= >= == !=   4
 *   additive       + -        5
 *   multiplicative * / %      6
 *   unary          - !        7
 *   power          ^          8   (right-assoc)
 *
 * Produces a plain-array AST:
 *
 *   ['k'=>'num'|'str', 'v'=>value]
 *   ['k'=>'var', 'v'=>name]
 *   ['k'=>'un',  'op'=>..., 'a'=>node]
 *   ['k'=>'bin', 'op'=>..., 'l'=>node, 'r'=>node]
 *   ['k'=>'tern', 'c'=>node, 't'=>node, 'f'=>node]
 *   ['k'=>'call', 'fn'=>name, 'args'=>node[]]
 *
 * The grammar is deliberately closed: no assignment, no loops, no property
 * access, no dynamic calls. Identifiers can only become variables (checked
 * at evaluation) or allow-listed function calls (checked here at parse
 * time) — there is no surface for code injection.
 */
final class ExpressionParser
{
    private const BINARY_BP = [
        '||' => 2, '&&' => 3,
        '<' => 4, '>' => 4, '<=' => 4, '>=' => 4, '==' => 4, '!=' => 4,
        '+' => 5, '-' => 5, '*' => 6, '/' => 6, '%' => 6, '^' => 8,
    ];

    private const RIGHT_ASSOC = ['^' => true];

    private const UNARY_BP = 7;
    private const TERNARY_BP = 1;
    private const MAX_DEPTH = 48;

    private int $pos = 0;
    private int $depth = 0;

    /**
     * @param  array<int, array{0: string, 1: mixed}>  $tokens
     * @param  array<int, string>  $functions
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $functions,
    ) {}

    /**
     * Parse the full token stream into an AST node.
     *
     * @return array<string, mixed>
     *
     * @throws ExpressionSyntaxError
     */
    public function parse(): array
    {
        if ($this->tokens === []) {
            throw new ExpressionSyntaxError('The expression is empty.');
        }

        $node = $this->parseExpression(0);

        if ($this->pos < count($this->tokens)) {
            $token = $this->tokens[$this->pos];

            throw new ExpressionSyntaxError(sprintf(
                'Unexpected %s in expression.',
                $token[0] === 'op' ? "'{$token[1]}'" : 'token',
            ));
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExpressionSyntaxError
     */
    private function parseExpression(int $minBp): array
    {
        $this->depth++;

        if ($this->depth > self::MAX_DEPTH) {
            throw new ExpressionSyntaxError('The expression nests too deeply.');
        }

        try {
            $left = $this->parsePrefix();

            while (true) {
                $token = $this->peek();

                // Ternary — the else branch parses greedily, which makes
                // chained ternaries right-associative for free.
                if ($token !== null && $token[0] === 'op' && $token[1] === '?') {
                    if (self::TERNARY_BP < $minBp) {
                        break;
                    }

                    $this->pos++;
                    $then = $this->parseExpression(0);
                    $this->expectOp(':');
                    $else = $this->parseExpression(0);

                    $left = ['k' => 'tern', 'c' => $left, 't' => $then, 'f' => $else];

                    continue;
                }

                if ($token === null || $token[0] !== 'op' || ! isset(self::BINARY_BP[$token[1]])) {
                    break;
                }

                $bp = self::BINARY_BP[$token[1]];

                if ($bp < $minBp) {
                    break;
                }

                $this->pos++;
                $right = $this->parseExpression(isset(self::RIGHT_ASSOC[$token[1]]) ? $bp : $bp + 1);

                $left = ['k' => 'bin', 'op' => $token[1], 'l' => $left, 'r' => $right];
            }

            return $left;
        } finally {
            $this->depth--;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ExpressionSyntaxError
     */
    private function parsePrefix(): array
    {
        $token = $this->peek();

        if ($token === null) {
            throw new ExpressionSyntaxError('The expression ends unexpectedly.');
        }

        [$kind, $value] = $token;

        if ($kind === 'num' || $kind === 'str') {
            $this->pos++;

            return ['k' => $kind, 'v' => $value];
        }

        if ($kind === 'ident') {
            $this->pos++;

            $next = $this->peek();

            if ($next !== null && $next[0] === 'op' && $next[1] === '(') {
                if (! in_array($value, $this->functions, true)) {
                    throw new ExpressionSyntaxError("Unknown function '{$value}'.");
                }

                $this->pos++; // consume (
                $args = [];

                if ($this->takeIfOp(')')) {
                    return ['k' => 'call', 'fn' => $value, 'args' => $args];
                }

                do {
                    $args[] = $this->parseExpression(0);
                } while ($this->takeIfOp(','));

                $this->expectOp(')');

                return ['k' => 'call', 'fn' => $value, 'args' => $args];
            }

            return ['k' => 'var', 'v' => $value];
        }

        if ($kind === 'op' && ($value === '-' || $value === '!')) {
            $this->pos++;

            return ['k' => 'un', 'op' => $value, 'a' => $this->parseExpression(self::UNARY_BP)];
        }

        if ($kind === 'op' && $value === '(') {
            $this->pos++;
            $node = $this->parseExpression(0);
            $this->expectOp(')');

            return $node;
        }

        throw new ExpressionSyntaxError("Unexpected '{$value}' in expression.");
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function takeIfOp(string $op): bool
    {
        $token = $this->peek();

        if ($token !== null && $token[0] === 'op' && $token[1] === $op) {
            $this->pos++;

            return true;
        }

        return false;
    }

    /**
     * @throws ExpressionSyntaxError
     */
    private function expectOp(string $op): void
    {
        if (! $this->takeIfOp($op)) {
            throw new ExpressionSyntaxError("Expected '{$op}' in expression.");
        }
    }
}
