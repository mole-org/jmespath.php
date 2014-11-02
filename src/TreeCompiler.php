<?php
namespace JmesPath;

/**
 * Tree visitor used to compile JMESPath expressions into native PHP code.
 */
class TreeCompiler
{
    private $indentation;
    private $source;
    private $vars;

    /**
     * @param array  $ast    AST to compile.
     * @param string $fnName The name of the function to generate.
     * @param string $expr   Expression being compiled.
     *
     * @return string
     */
    public function visit(array $ast, $fnName, $expr)
    {
        $this->vars = [];
        $this->source = $this->indentation = '';
        $this->write("<?php\n")
            ->write('use JmesPath\\TreeInterpreter as Ti;')
            ->write('use JmesPath\\FnDispatcher as Fn;')
            ->write('use JmesPath\\Utils;')
            ->write("// {$expr}")
            ->write('function %s(Ti $interpreter, $value) {', [$fnName])
            ->indent()
                ->write('$current = $value;')
                ->dispatch($ast)
                ->write('return $value;')
            ->outdent()
        ->write('}');

        return $this->source;
    }

    /**
     * @param array $node
     * @return mixed
     */
    private function dispatch(array $node)
    {
        return $this->{"visit_{$node['type']}"}($node);
    }

    /**
     * Creates a monotonically incrementing unique variable name by prefix.
     *
     * @param string $prefix Variable name prefix
     *
     * @return string
     */
    private function makeVar($prefix)
    {
        if (!isset($this->vars[$prefix])) {
            $this->vars[$prefix] = 0;
            return '$' . $prefix;
        }

        return '$' . $prefix . ++$this->vars[$prefix];
    }

    /**
     * Writes the given line of source code.
     * @param string $str        String to write
     * @param array  $positional Positional substitutions.
     * @return $this
     */
    private function write($str, array $positional = [])
    {
        $this->source .= $this->indentation
            . (!$positional ? $str : vsprintf($str, $positional)) . "\n";
        return $this;
    }

    /**
     * Decreases the indentation level of code being written
     * @return $this
     */
    private function outdent()
    {
        $this->indentation = substr($this->indentation, 0, -4);
        return $this;
    }

    /**
     * Increases the indentation level of code being written
     * @return $this
     */
    private function indent()
    {
        $this->indentation .= '    ';
        return $this;
    }

    private function visit_or(array $node)
    {
        $a = $this->makeVar('beforeOr');
        return $this
            ->write('%s = $value;', [$a])
            ->dispatch($node['children'][0])
            ->write('if (!$value && $value !== "0" && $value !== 0) {')
                ->indent()
                ->write('$value = %s;', [$a])
                ->dispatch($node['children'][1])
                ->outdent()
            ->write('}');
    }

    /**
     * Visits a non-terminal subexpression. Subexpressions wrapping nested
     * array accessors will be combined into a single if/then block.
     */
    private function visit_subexpression(array $node)
    {
        return $this
            ->dispatch($node['children'][0])
            ->write('if ($value !== null) {')
                ->indent()
                ->dispatch($node['children'][1])
                ->outdent()
            ->write('}');
    }

    /**
     * Visits a terminal identifier
     */
    private function visit_field(array $node)
    {
        $arr = '$value[' . var_export($node['value'], true) . ']';
        $obj = '$value->{' . var_export($node['value'], true) . '}';
        $this->write('if (is_array($value) || $value instanceof \\ArrayAccess) {')
                ->indent()
                ->write('$value = isset(%s) ? %s : null;', [$arr, $arr])
                ->outdent()
            ->write('} elseif ($value instanceof \\stdClass) {')
                ->indent()
                ->write('$value = isset(%s) ? %s : null;', [$obj, $obj])
                ->outdent()
            ->write("} else {")
                ->indent()
                ->write('$value = null;')
                ->outdent()
            ->write("}");

        return $this;
    }

    /**
     * Visits a terminal index
     */
    private function visit_index(array $node)
    {
        if ($node['value'] >= 0) {
            $check = '$value[' . $node['value'] . ']';
            return $this->write(
                '$value = (is_array($value) || $value instanceof \\ArrayAccess)'
                    . ' && isset(%s) ? %s : null;',
                [$check, $check]
            );
        }

        $a = $this->makeVar('count');
        return $this
            ->write('if (is_array($value) || ($value instanceof \\ArrayAccess && $value instanceof \\Countable)) {')
                ->indent()
                ->write('%s = count($value) + %s;', [$a, $node['value']])
                ->write('$value = isset($value[%s]) ? $value[%s] : null;', [$a, $a])
                ->outdent()
            ->write('} else {')
                ->indent()
                ->write('$value = null;')
                ->outdent()
            ->write('}');
    }

    private function visit_literal(array $node)
    {
        return $this->write('$value = %s;', [var_export($node['value'], true)]);
    }

    private function visit_pipe(array $node)
    {
        return $this
            ->dispatch($node['children'][0])
            ->write('$current = $value;')
            ->dispatch($node['children'][1]);
    }

    private function visit_multi_select_list(array $node)
    {
        return $this->visit_multi_select_hash($node);
    }

    private function visit_multi_select_hash(array $node)
    {
        $tmpCurrent = $this->makeVar('cur');
        $listVal = $this->makeVar('list');
        $value = $this->makeVar('prev');
        $this->write('if ($value !== null) {')
            ->indent()
                ->write('%s = [];', [$listVal])
                ->write('%s = $current;', [$tmpCurrent])
                ->write('%s = $value;', [$value]);

        $first = true;
        foreach ($node['children'] as $child) {
            if (!$first) {
                $this->write('$value = %s;', [$value]);
            }
            $first = false;
            if ($node['type'] == 'multi_select_hash') {
                $this->dispatch($child['children'][0]);
                $key = var_export($child['value'], true);
                $this->write('%s[%s] = $value;', [$listVal, $key]);
            } else {
                $this->dispatch($child);
                $this->write('%s[] = $value;', [$listVal]);
            }
        }

        return $this
            ->write('$value = %s;', [$listVal])
            ->write('$current = %s;', [$tmpCurrent])
            ->outdent()
            ->write('}');
    }

    private function visit_function(array $node)
    {
        $value = $this->makeVar('val');
        $current = $this->makeVar('current');
        $args = $this->makeVar('args');
        $this->write('%s = $value;', [$value])
            ->write('%s = $current;', [$current])
            ->write('%s = [];', [$args]);

        foreach ($node['children'] as $arg) {
            $this->dispatch($arg);
            $this->write('%s[] = $value;', [$args])
                ->write('$current = %s;', [$current])
                ->write('$value = %s;', [$value]);
        }

        return $this->write(
            '$value = Fn::getInstance()->__invoke("%s", %s);',
            [$node['value'], $args]
        );
    }

    private function visit_slice(array $node)
    {
        return $this
            ->write('$value = Fn::getInstance()->__invoke("slice", [')
                ->indent()
                ->write('$value, %s, %s, %s', [
                    var_export($node['value'][0], true),
                    var_export($node['value'][1], true),
                    var_export($node['value'][2], true)
                ])
                ->outdent()
            ->write(']);');
    }

    private function visit_current(array $node)
    {
        return $this->write('// Visiting current node (no-op)');
    }

    private function visit_expref(array $node)
    {
        $child = var_export($node['children'][0], true);
        return $this->write('$value = function ($value) use ($interpreter) {')
            ->indent()
            ->write('return $interpreter->visit(%s, $value);', [$child])
            ->outdent()
        ->write('};');
    }

    private function visit_flatten(array $node)
    {
        $this->dispatch($node['children'][0]);
        $merged = $this->makeVar('merged');
        $val = $this->makeVar('val');

        $this
            ->write('// Visiting merge node')
            ->write('if (!Utils::isArray($value)) {')
                ->indent()
                ->write('$value = null;')
                ->outdent()
            ->write('} else {')
                ->indent()
                ->write('%s = [];', [$merged])
                ->write('foreach ($value as %s) {', [$val])
                    ->indent()
                    ->write('if (is_array(%s) && isset(%s[0])) {', [$val, $val])
                        ->indent()
                        ->write('%s = array_merge(%s, %s);', [$merged, $merged, $val])
                        ->outdent()
                    ->write('} elseif (%s !== []) {', [$val])
                        ->indent()
                        ->write('%s[] = %s;', [$merged, $val])
                        ->outdent()
                    ->write('}')
                    ->outdent()
                ->write('}')
                ->write('$value = %s;', [$merged])
                ->outdent()
            ->write('}');

        return $this;
    }

    private function visit_projection(array $node)
    {
        $val = $this->makeVar('val');
        $collected = $this->makeVar('collected');
        $this->write('// Visiting projection node')
            ->dispatch($node['children'][0])
            ->write('');

        if (!isset($node['from'])) {
            $this->write('if (!is_array($value) || !($value instanceof \stdClass)) $value = null;');
        } elseif ($node['from'] == 'object') {
            $this->write('if (!Utils::isObject($value)) $value = null;');
        } elseif ($node['from'] == 'array') {
            $this->write('if (!Utils::isArray($value)) $value = null;');
        }

        $this->write('if ($value !== null) {')
            ->indent()
            ->write('%s = [];', [$collected])
            ->write('foreach ((array) $value as %s) {', [$val])
                ->indent()
                ->write('$value = %s;', [$val])
                ->dispatch($node['children'][1])
                ->write('if ($value !== null) {')
                    ->indent()
                    ->write('%s[] = $value;', [$collected])
                    ->outdent()
                ->write('}')
                ->outdent()
            ->write('}')
            ->write('$value = %s;', [$collected])
            ->outdent()
        ->write('}');

        return $this;
    }

    private function visit_condition(array $node)
    {
        return $this
            ->write('// Visiting projection node')
            ->dispatch($node['children'][0])
            ->write('if ($value !== null) {')
                ->indent()
                ->dispatch($node['children'][1])
                ->outdent()
            ->write('}');
    }

    private function visit_comparator(array $node)
    {
        $value = $this->makeVar('val');
        $tmpCurrent = $this->makeVar('cur');
        $a = $this->makeVar('left');
        $b = $this->makeVar('right');

        $this
            ->write('// Visiting comparator node')
            ->write('%s = $value;', [$value])
            ->write('%s = $current;', [$tmpCurrent])
            ->dispatch($node['children'][0])
            ->write('%s = $value;', [$a])
            ->write('$value = %s;', [$value])
            ->dispatch($node['children'][1])
            ->write('%s = $value;', [$b]);

        if ($node['value'] == '==') {
            $this->write('$result = Utils::equal(%s, %s);', [$a, $b]);
        } elseif ($node['value'] == '!=') {
            $this->write('$result = !Utils::equal(%s, %s);', [$a, $b]);
        } else {
            $this->write(
                '$result = is_int(%s) && is_int(%s) && %s %s %s;',
                [$a, $b, $a, $node['value'], $b]
            );
        }

        return $this
            ->write('$value = $result === true ? %s : null;', [$value])
            ->write('$current = %s;', [$tmpCurrent]);
    }

    /** @internal */
    public function __call($method, $args)
    {
        throw new \RuntimeException(
            sprintf('Invalid node encountered: %s', json_encode($args[0]))
        );
    }
}
