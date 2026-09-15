<?php

namespace App\Support\Dashboard;

/**
 * Safe runtime math evaluation for dashboard widgets.
 *
 * Pipeline: tokenize → Pratt parse → evaluate against a variable context.
 * There is no eval() and no code path that compiles expression text into
 * PHP. The grammar is closed (numbers, strings, variables, allow-listed
 * functions, arithmetic/comparison/logic/ternary), character-set, length,
 * token and nesting limits are enforced, and every failure surfaces as an
 * ExpressionSyntaxError that widget factories let bubble so the layout
 * engine can degrade the single widget to an error card.
 */
final class ExpressionEngine
{
    public const FUNCTIONS = ['round', 'min', 'max', 'abs', 'if', 'coalesce', 'sum', 'pct'];

    private const MAX_SOURCE_LENGTH = 512;
    private const MAX_TOKENS = 256;

    /** Legal characters: identifiers, numbers, strings, whitespace, operators. */
    private const ALLOWED_CHARS = '/^[\p{L}\p{N}_\s\'"().,+\-*\/%^<>=!?:&|]+$/u';

    /**
     * Evaluate an expression against the given variables.
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws ExpressionSyntaxError
     */
    public function evaluate(string $expression, array $variables = []): mixed
    {
        $ast = $this->parse($expression);

        return $this->evalNode($ast, $variables);
    }

    /**
     * Parse an expression into an AST (exposed for validation/inspection).
     *
     * @return array<string, mixed>
     *
     * @throws ExpressionSyntaxError
     */
    public function parse(string $expression): array
    {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            throw new ExpressionSyntaxError('The expression is empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_SOURCE_LENGTH) {
            throw new ExpressionSyntaxError('The expression is too long (max '.self::MAX_SOURCE_LENGTH.' characters).');
        }

        if (! preg_match(self::ALLOWED_CHARS, $trimmed)) {
            throw new ExpressionSyntaxError('The expression contains characters that are not allowed.');
        }

        return (new ExpressionParser($this->tokenize($trimmed), self::FUNCTIONS))->parse();
    }

    /**
     * Read an expression back as a visual node graph for the drag-drop
     * expression tree editor: numbers, metrics, + - * / % ^ operators and
     * allow-listed function calls. Returns the editor payload
     * ['nodes' => [...], 'root' => id] with x/y canvas coordinates, or null
     * when the expression uses anything the canvas cannot express
     * (strings, comparisons, ternaries) so the UI can fall back to the
     * advanced editor without data loss.
     *
     * @return array{nodes: array<int, array{id: string, kind: string, value: string, inputs: array<int, string>, x: int, y: int}>, root: string}|null
     */
    public function toTree(string $expression): ?array
    {
        try {
            $ast = $this->parse($expression);
        } catch (ExpressionSyntaxError) {
            return null;
        }

        $nodes = [];
        $counter = 0;

        // Input slots per function node on the canvas. Calls with more
        // arguments than slots cannot be drawn and fall back to advanced.
        $slots = ['round' => 2, 'min' => 2, 'max' => 2, 'abs' => 1, 'if' => 3, 'coalesce' => 2, 'sum' => 2, 'pct' => 2];

        $build = null;
        $build = function (array $node, int $depth) use (&$build, &$nodes, &$counter, $slots): ?string {
            $id = 'n'.(++$counter);

            $record = function (string $kind, string $value, array $inputs) use ($id, $depth, &$nodes): void {
                $nodes[$id] = ['id' => $id, 'kind' => $kind, 'value' => $value, 'inputs' => $inputs, 'depth' => $depth];
            };

            switch ($node['k']) {
                case 'num':
                    $record('number', $this->formatTreeNumber($node['v']), []);
                    break;

                case 'var':
                    $record('metric', $node['v'], []);
                    break;

                case 'bin':
                    if (! in_array($node['op'], ['+', '-', '*', '/', '%', '^'], true)) {
                        return null;
                    }

                    $left = $build($node['l'], $depth + 1);
                    $right = $build($node['r'], $depth + 1);

                    if ($left === null || $right === null) {
                        return null;
                    }

                    $record('op', $node['op'], [$left, $right]);
                    break;

                case 'call':
                    $fn = (string) $node['fn'];

                    if (! isset($slots[$fn]) || count($node['args']) > $slots[$fn]) {
                        return null;
                    }

                    $inputs = [];

                    foreach ($node['args'] as $arg) {
                        $child = $build($arg, $depth + 1);

                        if ($child === null) {
                            return null;
                        }

                        $inputs[] = $child;
                    }

                    $record('fn', $fn, $inputs);
                    break;

                default: // str, un, tern, comparisons
                    return null;
            }

            return $id;
        };

        $root = $build($ast, 0);

        if ($root === null) {
            return null;
        }

        // Tidy layout: leaves sit left, results flow right; each parent is
        // vertically centered on its inputs.
        $byId = $nodes;
        $maxDepth = max(array_column($byId, 'depth'));
        $leafCounter = 0;

        $layout = function (string $id) use (&$layout, &$byId, &$leafCounter, $maxDepth): int {
            $node = $byId[$id];

            if ($node['inputs'] === []) {
                $y = $leafCounter++ * 78 + 14;
            } else {
                $children = array_map(fn (string $child) => $layout($child), $node['inputs']);
                $y = (int) round((min($children) + max($children)) / 2);
            }

            $byId[$id]['y'] = $y;
            $byId[$id]['x'] = 14 + ($maxDepth - $node['depth']) * 176;

            return $y;
        };

        $layout($root);

        return [
            'nodes' => array_map(fn (array $node) => [
                'id' => $node['id'],
                'kind' => $node['kind'],
                'value' => $node['value'],
                'inputs' => $node['inputs'],
                'x' => $node['x'],
                'y' => $node['y'],
            ], array_values($byId)),
            'root' => $root,
        ];
    }

    /**
     * Render an AST number for the tree editor: integers without a
     * decimal point, floats trimmed of trailing zeros.
     */
    private function formatTreeNumber(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * @return array<int, array{0: string, 1: mixed}>
     *
     * @throws ExpressionSyntaxError
     */
    private function tokenize(string $source): array
    {
        // Alternative branch of bare whitespace keeps the byte-consumption
        // check honest: every byte of source must land in exactly one match.
        preg_match_all(
            '/\s*(\d+\.\d+|\d+|"[^"]*"|\'[^\']*\'|[\p{L}_][\p{L}\p{N}_]*|<=|>=|==|!=|&&|\|\||[-+*\/%^()!,<>?:])|\s+/u',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $tokens = [];
        $consumed = 0;

        foreach ($matches as $match) {
            $consumed += strlen($match[0]);
            $capture = $match[1] ?? '';

            if ($capture === '') {
                continue; // whitespace-only match
            }

            if (is_numeric($capture)) {
                $tokens[] = ['num', (float) $capture];
            } elseif ($capture[0] === '"' || $capture[0] === "'") {
                $tokens[] = ['str', substr($capture, 1, -1)];
            } elseif (preg_match('/^[\p{L}_]/u', $capture) === 1) {
                $tokens[] = ['ident', $capture];
            } else {
                $tokens[] = ['op', $capture];
            }
        }

        if ($consumed < strlen($source)) {
            throw new ExpressionSyntaxError('The expression could not be tokenized fully.');
        }

        if (count($tokens) > self::MAX_TOKENS) {
            throw new ExpressionSyntaxError('The expression is too complex (max '.self::MAX_TOKENS.' tokens).');
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $variables
     *
     * @throws ExpressionSyntaxError
     */
    private function evalNode(array $node, array $variables): mixed
    {
        return match ($node['k']) {
            'num', 'str' => $node['v'],
            'var' => $this->evalVariable($node['v'], $variables),
            'un' => $node['op'] === '!'
                ? ! $this->evalNode($node['a'], $variables)
                : - $this->evalNode($node['a'], $variables),
            'bin' => $this->evalBinary($node, $variables),
            'tern' => $this->evalNode($node['c'], $variables)
                ? $this->evalNode($node['t'], $variables)
                : $this->evalNode($node['f'], $variables),
            'call' => $this->evalCall($node, $variables),
            default => throw new ExpressionSyntaxError('Unknown AST node.'),
        };
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws ExpressionSyntaxError
     */
    private function evalVariable(string $name, array $variables): mixed
    {
        if (! array_key_exists($name, $variables)) {
            throw new ExpressionSyntaxError("Unknown variable '{$name}'.");
        }

        return $variables[$name];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $variables
     *
     * @throws ExpressionSyntaxError
     */
    private function evalBinary(array $node, array $variables): mixed
    {
        $left = $this->evalNode($node['l'], $variables);
        $right = $this->evalNode($node['r'], $variables);

        return match ($node['op']) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right == 0 ? null : $left / $right,
            '%' => $right == 0 ? null : $left % $right,
            '^' => $left ** $right,
            '<' => $left < $right,
            '>' => $left > $right,
            '<=' => $left <= $right,
            '>=' => $left >= $right,
            '==' => $left == $right,
            '!=' => $left != $right,
            '&&' => $left && $right,
            '||' => $left || $right,
            default => throw new ExpressionSyntaxError("Unknown operator '{$node['op']}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $variables
     *
     * @throws ExpressionSyntaxError
     */
    private function evalCall(array $node, array $variables): mixed
    {
        $args = array_map(fn (array $arg) => $this->evalNode($arg, $variables), $node['args']);
        $fn = $node['fn'];

        switch ($fn) {
            case 'round':
                $this->arity($fn, $args, 1, 2);

                return round((float) $args[0], (int) ($args[1] ?? 0));

            case 'min':
                $this->arity($fn, $args, 1);

                return min(...$args);

            case 'max':
                $this->arity($fn, $args, 1);

                return max(...$args);

            case 'abs':
                $this->arity($fn, $args, 1, 1);

                return abs((float) $args[0]);

            case 'if':
                $this->arity($fn, $args, 2, 3);

                return $args[0] ? $args[1] : ($args[2] ?? null);

            case 'coalesce':
                $this->arity($fn, $args, 2);

                foreach ($args as $arg) {
                    if ($arg !== null) {
                        return $arg;
                    }
                }

                return null;

            case 'sum':
                $this->arity($fn, $args, 1);

                return array_sum($args);

            case 'pct':
                $this->arity($fn, $args, 2, 2);

                $denominator = (float) $args[1];

                return $denominator == 0.0 ? 0.0 : round(((float) $args[0] / $denominator) * 100, 1);
        }

        throw new ExpressionSyntaxError("Unknown function '{$fn}'.");
    }

    /**
     * @param  array<int, mixed>  $args
     *
     * @throws ExpressionSyntaxError
     */
    private function arity(string $fn, array $args, int $min, ?int $max = null): void
    {
        $count = count($args);
        $max = $max ?? PHP_INT_MAX;

        if ($count < $min || $count > $max) {
            throw new ExpressionSyntaxError(
                $min === $max
                    ? "Function '{$fn}' expects exactly {$min} argument(s)."
                    : "Function '{$fn}' expects between {$min} and {$max} arguments."
            );
        }
    }
}
