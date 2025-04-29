<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * A small helper class to track types discovered for a property.
 */
class TypeTracker
{
    /**
     * @var array<string> A set of types discovered for this property, e.g. ['int', 'null', 'SomeClass']
     */
    private $types = [];

    /**
     * Merges a new type or types into our known set.
     *
     * @param string|string[] $newTypes
     */
    public function addType($newTypes): void
    {
        foreach ((array)$newTypes as $t) {
            // Convert internal "NULL" or "null" to unified "null"
            if (strtolower($t) === 'null') {
                $t = 'null';
            }
            // Normalize simpler scalar type names used by PHP gettype()
            // E.g., 'integer' => 'int', 'boolean' => 'bool'
            switch ($t) {
                case 'integer':
                    $t = 'int';
                    break;
                case 'boolean':
                    $t = 'bool';
                    break;
                case 'double':
                    $t = 'float';
                    break;
            }
            // Avoid duplicates
            if (!in_array($t, $this->types, true)) {
                $this->types[] = $t;
            }
        }
    }

    /**
     * Returns the combined, inferred type as a final string.
     *
     * Heuristics:
     * - If no usage found, return "unknown".
     * - If exactly one type and it's not "null", return that type.
     * - If type "null" plus exactly one other type X => "?X".
     * - If multiple distinct types (e.g. string|int), consider union
     *   if we only have 2 types; otherwise "mixed" is safer.
     * - If we use union, ensure the environment is >= PHP 8.0 (else "mixed").
     */
    public function getFinalType(): string
    {
        if (empty($this->types)) {
            return 'unknown';
        }

        // Distinct types
        $uniqueTypes = array_values($this->types);
        sort($uniqueTypes);

        // If we only have one type and it's not null:
        if (count($uniqueTypes) === 1 && $uniqueTypes[0] !== 'null') {
            return $uniqueTypes[0];
        }

        // If we have exactly two types and one is null => ?Type
        if (count($uniqueTypes) === 2 && in_array('null', $uniqueTypes, true)) {
            $nonNull = ($uniqueTypes[0] === 'null') ? $uniqueTypes[1] : $uniqueTypes[0];
            return '?' . $nonNull;
        }

        // If we have exactly two non-null types, consider union (PHP 8+)
        // Otherwise fallback to "mixed"
        if (count($uniqueTypes) === 2 && !in_array('null', $uniqueTypes, true)) {
            // For demonstration, let's assume we can produce a union type (PHP 8+).
            // If the code must remain PHP 7 compatible, you'd return "mixed" or something else here.
            return implode('|', $uniqueTypes);
        }

        // If 3 or more distinct or includes null among others => "mixed"
        return 'mixed';
    }
}

class TypeInferenceVisitor extends NodeVisitorAbstract
{
    /**
     * @var string|null Keeps track of the current class name we are in during traversal.
     */
    private $currentClass = null;

    /**
     * @var array<string,array<string,TypeTracker>> [$className => [$propertyName => TypeTracker]]
     */
    private $propertyTypes = [];

    /**
     * @var array<int,array{class:string,property:string,type:string,reason:string}>
     */
    public $results = [];

    /**
     * Enter any node in the AST. We use this for:
     * - Detecting when we enter a class
     * - Collecting property default value types, doc comment @var types
     * - Collecting usage patterns for properties
     */
    public function enterNode(Node $node)
    {
        // If we are entering a class node, capture the class name.
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name ? $node->name->name : null;

            // Initialize property type trackers for all untyped properties in this class
            foreach ($node->getProperties() as $property) {
                // Skip typed properties
                if ($property->type !== null) {
                    continue;
                }

                foreach ($property->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->ensurePropertyTracker($this->currentClass, $propName);

                    // 1) Check default value type
                    if ($prop->default instanceof Node\Scalar) {
                        // e.g. "int", "string", ...
                        $this->propertyTypes[$this->currentClass][$propName]
                            ->addType(gettype($prop->default->value));
                    } elseif ($prop->default instanceof Node\Expr\Array_) {
                        $this->propertyTypes[$this->currentClass][$propName]
                            ->addType('array');
                    } elseif ($prop->default instanceof Node\Expr\ConstFetch) {
                        // Possibly "null" or other constants
                        $constName = strtolower($prop->default->name->toString());
                        $this->propertyTypes[$this->currentClass][$propName]
                            ->addType($constName);
                    }

                    // 2) Check doc comment @var
                    $docComment = $property->getDocComment();
                    if ($docComment && preg_match('/@var\s+([^\s]+)/', $docComment->getText(), $matches)) {
                        $this->propertyTypes[$this->currentClass][$propName]
                            ->addType($matches[1]);
                    }
                }
            }
        }

        // If we're in a class context, detect property usage
        if ($this->currentClass) {
            // 3) Check property assignment usage: $this->property = <expression>;
            if (
                $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\PropertyFetch
                && $node->var->var instanceof Node\Expr\Variable
                && $node->var->var->name === 'this'
            ) {
                $propName = $node->var->name instanceof Node\Identifier
                    ? $node->var->name->name
                    : null;

                // If it's something like $this->{$someDynamicName}, treat as dynamic/unsupported
                if (!$propName) {
                    // dynamic property name
                    // ensure we track it
                    $this->ensurePropertyTracker($this->currentClass, '(dynamic)');
                    $this->propertyTypes[$this->currentClass]['(dynamic)']->addType('dynamic (unsupported)');
                } else {
                    $this->ensurePropertyTracker($this->currentClass, $propName);

                    // Inspect the right-hand side and attempt some basic inference:
                    $rhsType = $this->inferExpressionType($node->expr);
                    $this->propertyTypes[$this->currentClass][$propName]->addType($rhsType);
                }
            }

            // 4) Check for null coalescing, isset, or comparisons to null
            //    If property is tested for null, we add "null" as a possibility
            $this->checkNullUsage($node);
        }
    }

    /**
     * When we leave a class node, reset currentClass context.
     */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            // We now have enough info for all properties in this class
            $className = $this->currentClass;
            $this->currentClass = null;

            // Prepare final results for untyped properties
            if (isset($this->propertyTypes[$className])) {
                foreach ($this->propertyTypes[$className] as $propName => $tracker) {
                    // If property was never declared in the property list (e.g. dynamic) skip
                    // or if property is typed, skip. But we track typed checks above, so let's just finalize:
                    $finalType = $tracker->getFinalType();

                    // If this was the magic '(dynamic)' entry, skip normal printing
                    if ($propName === '(dynamic)') {
                        // Output a single dynamic-prop note
                        $this->results[] = [
                            'class' => $className,
                            'property' => '{dynamic}',
                            'type' => $finalType,
                            'reason' => 'One or more dynamic property name usages detected.'
                        ];
                        continue;
                    }

                    // Build a reason string from usage or fallback if nothing
                    $reason = 'Inferred from usage within class.';
                    if ($finalType === 'unknown') {
                        $reason = 'No usage or default value found. Possibly unused.';
                    }

                    $this->results[] = [
                        'class' => $className,
                        'property' => $propName,
                        'type' => $finalType,
                        'reason' => $reason
                    ];
                }
            }
        }
    }

    /**
     * Safely create a TypeTracker for the given class & property name if needed.
     */
    private function ensurePropertyTracker(string $className, string $propName): void
    {
        if (!isset($this->propertyTypes[$className])) {
            $this->propertyTypes[$className] = [];
        }
        if (!isset($this->propertyTypes[$className][$propName])) {
            $this->propertyTypes[$className][$propName] = new TypeTracker();
        }
    }

    /**
     * A naive expression type inference method.
     * If it's a scalar, array, new Object, or null, we try to map it to a type.
     * All else => "mixed"
     */
    private function inferExpressionType(Node $expr): string
    {
        // $this->property = 42; => int
        if ($expr instanceof Node\Scalar\LNumber) {
            return 'int';
        }
        // $this->property = 3.14; => float
        if ($expr instanceof Node\Scalar\DNumber) {
            return 'float';
        }
        // $this->property = 'hello'; => string
        if ($expr instanceof Node\Scalar\String_) {
            return 'string';
        }
        // $this->property = []; => array
        if ($expr instanceof Node\Expr\Array_) {
            return 'array';
        }
        // $this->property = null; => null
        if ($expr instanceof Node\Expr\ConstFetch) {
            $const = strtolower($expr->name->toString());
            if ($const === 'null') {
                return 'null';
            }
            // Could be "false" => bool, "true" => bool, etc.
            if (in_array($const, ['true', 'false'], true)) {
                return 'bool';
            }
            // If something else, treat as "mixed"
            return 'mixed';
        }
        // $this->property = new ClassName(); => ClassName
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        // If it’s some variable or function call or complicated expression => "mixed"
        return 'mixed';
    }

    /**
     * Check for patterns that imply null usage:
     * - if ($this->prop === null) ...
     * - isset($this->prop), empty($this->prop)
     * - $this->prop ?? ...
     */
    private function checkNullUsage(Node $node): void
    {
        // if ($this->prop === null)
        if (
            $node instanceof Node\Expr\BinaryOp\Identical
            || $node instanceof Node\Expr\BinaryOp\NotIdentical
            || $node instanceof Node\Expr\BinaryOp\Equal
            || $node instanceof Node\Expr\BinaryOp\NotEqual
        ) {
            // left or right might be $this->prop and the other might be null
            $operands = [
                $node->left,
                $node->right
            ];
            $propName = null;
            $hasNull = false;
            foreach ($operands as $operand) {
                if (
                    $operand instanceof Node\Expr\PropertyFetch
                    && $operand->var instanceof Node\Expr\Variable
                    && $operand->var->name === 'this'
                    && $operand->name instanceof Node\Identifier
                ) {
                    $propName = $operand->name->toString();
                }
                if (
                    $operand instanceof Node\Expr\ConstFetch
                    && strtolower($operand->name->toString()) === 'null'
                ) {
                    $hasNull = true;
                }
            }
            if ($propName && $hasNull) {
                $this->ensurePropertyTracker($this->currentClass, $propName);
                $this->propertyTypes[$this->currentClass][$propName]->addType('null');
            }
        }

        // isset($this->prop) or empty($this->prop)
        if ($node instanceof Node\Expr\Isset_ || $node instanceof Node\Expr\Empty_) {
            foreach ($node->vars as $var) {
                if (
                    $var instanceof Node\Expr\PropertyFetch
                    && $var->var instanceof Node\Expr\Variable
                    && $var->var->name === 'this'
                ) {
                    // We can't tell which property if dynamic, but if it's simple we can:
                    $propName = $var->name instanceof Node\Identifier
                        ? $var->name->name
                        : null;
                    if (!$propName) {
                        $this->ensurePropertyTracker($this->currentClass, '(dynamic)');
                        $this->propertyTypes[$this->currentClass]['(dynamic)']->addType('dynamic (unsupported)');
                    } else {
                        $this->ensurePropertyTracker($this->currentClass, $propName);
                        // Checking isset/empty often implies it *could* be null
                        $this->propertyTypes[$this->currentClass][$propName]->addType('null');
                    }
                }
            }
        }

        // $this->prop ?? 'default'
        // The left side being $this->prop suggests it might be null
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            $left = $node->left;
            if (
                $left instanceof Node\Expr\PropertyFetch
                && $left->var instanceof Node\Expr\Variable
                && $left->var->name === 'this'
            ) {
                $propName = $left->name instanceof Node\Identifier
                    ? $left->name->name
                    : null;
                if (!$propName) {
                    $this->ensurePropertyTracker($this->currentClass, '(dynamic)');
                    $this->propertyTypes[$this->currentClass]['(dynamic)']->addType('dynamic (unsupported)');
                } else {
                    $this->ensurePropertyTracker($this->currentClass, $propName);
                    $this->propertyTypes[$this->currentClass][$propName]->addType('null');
                }
            }
        }
    }
}

// -------------------------- SCRIPT START -------------------------- //

$filename = $argv[1] ?? 'Test.php';
if (!file_exists($filename)) {
    echo "File not found: $filename\n";
    exit(1);
}

$code = file_get_contents($filename);
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser = new NodeTraverser();
$visitor = new TypeInferenceVisitor();
$traverser->addVisitor($visitor);

try {
    $ast = $parser->parse($code);
    $traverser->traverse($ast);

    // Output the suggestions
    foreach ($visitor->results as $result) {
        echo "#Class: {$result['class']}\n";
        echo "#Property: \${$result['property']}\n";
        echo "#Suggested Type: {$result['type']}\n";
        echo "#Reason: {$result['reason']}\n\n";
    }
} catch (Error $e) {
    echo "Parse error: {$e->getMessage()}\n";
}
