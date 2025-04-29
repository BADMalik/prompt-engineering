<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Collects information about classes/properties, and how they're used.
 */
class PropertyUsageCollector extends NodeVisitorAbstract
{
    /**
     * We store data per class as:
     *   $collectedData[$className] = [
     *       'properties' => [
     *           $propName => [
     *               'node' => Node\Stmt\Property,
     *               'usages' => array of usage info (assignments, null checks, etc.)
     *           ],
     *           ...
     *       ]
     *   ];
     *
     * @var array<string, array<string, mixed>>
     */
    private $collectedData = [];

    /**
     * Current class name we’re traversing.
     *
     * @var string|null
     */
    private $currentClass;

    public function enterNode(Node $node)
    {
        // Track when we enter a class
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name ? $node->name->toString() : null;
            if (!isset($this->collectedData[$this->currentClass])) {
                $this->collectedData[$this->currentClass] = [
                    'properties' => [],
                    'nameNode' => $node
                ];
            }
        }

        // If we are in a class scope, record class properties that have no explicit type.
        if ($this->currentClass !== null) {
            if ($node instanceof Node\Stmt\Property) {
                // Skip typed properties
                if ($node->type !== null) {
                    return null;
                }

                // We only care about untyped property declarations
                foreach ($node->props as $prop) {
                    $propName = $prop->name->toString();
                    $this->collectedData[$this->currentClass]['properties'][$propName] = [
                        'node' => $node,
                        'usages' => []
                    ];
                }
            }

            // Check usage of properties: we'll look for $this->prop in various contexts
            if (
                $node instanceof Node\Expr\Assign ||
                $node instanceof Node\Expr\AssignOp
            ) {
                // e.g. $this->prop = <expr>;
                $this->handleAssignment($node);
            }

            // Null checks, isset or null coalescing, etc.
            if ($node instanceof Node\Expr\Isset_) {
                $this->handleIsset($node);
            }

            if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
                $this->handleCoalesce($node);
            }

            if (
                $node instanceof Node\Expr\Empty_ ||
                $node instanceof Node\Expr\UnaryOp\UnsetCast
            ) {
                // optional: handle empty() or (unset) $this->prop if desired
            }

            if ($node instanceof Node\Expr\PropertyFetch) {
                // Catch general usage even if not an assignment
                $this->handleGeneralUsage($node);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // When we exit a class, reset currentClass
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        }
        return null;
    }

    /**
     * Handle assignments like $this->prop = <expr>.
     */
    private function handleAssignment(Node $assignNode)
    {
        // The left side might be a PropertyFetch
        $leftNode = $assignNode->var;
        if ($leftNode instanceof Node\Expr\PropertyFetch) {
            // Check if it's $this->something
            $propertyName = $this->extractThisPropertyName($leftNode);
            if (!$propertyName) {
                return;
            }
            // Gather the right side's type if we can guess it
            $rightType = $this->inferExprType($assignNode->expr);
            $this->recordUsage($propertyName, 'assignment', $rightType);
        }
    }

    /**
     * Handle isset($this->prop, ...).
     */
    private function handleIsset(Node\Expr\Isset_ $node)
    {
        foreach ($node->vars as $var) {
            if ($var instanceof Node\Expr\PropertyFetch) {
                $propertyName = $this->extractThisPropertyName($var);
                if (!$propertyName) {
                    continue;
                }
                $this->recordUsage($propertyName, 'nullCheck', null);
            }
        }
    }

    /**
     * Handle $this->prop ?? <expr>.
     */
    private function handleCoalesce(Node\Expr\BinaryOp\Coalesce $node)
    {
        $left = $node->left;
        if ($left instanceof Node\Expr\PropertyFetch) {
            $propertyName = $this->extractThisPropertyName($left);
            if ($propertyName) {
                $this->recordUsage($propertyName, 'nullCheck', null);
            }
        }
    }

    /**
     * General property fetch usage: $this->prop
     * Not an assignment, but we track that it's being read at least.
     */
    private function handleGeneralUsage(Node\Expr\PropertyFetch $node)
    {
        $propertyName = $this->extractThisPropertyName($node);
        if (!$propertyName) {
            return;
        }
        $this->recordUsage($propertyName, 'read', null);
    }

    /**
     * Extract property name from $this->someProperty usage.
     */
    private function extractThisPropertyName(Node\Expr\PropertyFetch $fetch)
    {
        // Must be $this-><identifier>, not $this->$something
        if (
            $fetch->var instanceof Node\Expr\Variable &&
            $fetch->var->name === 'this'
        ) {
            if ($fetch->name instanceof Node\Identifier) {
                return $fetch->name->toString();
            } else {
                // dynamic property fetch like $this->{$something}
                return null;
            }
        }
        return null;
    }

    /**
     * Record usage into $collectedData for the current class.
     */
    private function recordUsage(string $propertyName, string $usageKind, ?string $usageType)
    {
        if (!isset($this->collectedData[$this->currentClass]['properties'][$propertyName])) {
            // Possibly typed or not declared
            return;
        }
        $this->collectedData[$this->currentClass]['properties'][$propertyName]['usages'][] = [
            'kind' => $usageKind,
            'type' => $usageType
        ];
    }

    /**
     * Attempts to infer a naive scalar/object type from an expression node.
     */
    private function inferExprType(Node $expr): ?string
    {
        // Check for literal scalars
        if ($expr instanceof Node\Scalar\LNumber) {
            return 'int';
        }
        if ($expr instanceof Node\Scalar\DNumber) {
            return 'float';
        }
        if ($expr instanceof Node\Scalar\String_) {
            return 'string';
        }
        if ($expr instanceof Node\Expr\Array_) {
            return 'array';
        }
        // Check for null
        if ($expr instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'null') {
                return 'null';
            } elseif ($name === 'true' || $name === 'false') {
                return 'bool';
            }
            // [0, empty string, etc. not handled here]
        }
        // e.g. new SomeClass(...)
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString(); // object type
        }
        // Could add more inference logic (cast, function return, etc.)

        return null; // unknown or complex expression
    }

    /**
     * Exposes the final data about collected usage.
     * @return array
     */
    public function getCollectedData(): array
    {
        return $this->collectedData;
    }
}

/**
 * Infers and finalizes property types given the usage data.
 */
class TypeResolver
{
    /**
     * For each property usage set, figure out final type suggestion.
     *
     * @param array $collectedData from PropertyUsageCollector
     * @return array Array of suggestions, with structure:
     *  [
     *     [
     *       'class' => 'User',
     *       'property' => 'age',
     *       'type' => '?int',
     *       'reason' => 'Assigned int in constructor and checked for null.'
     *     ],
     *     ...
     *  ]
     */
    public function resolve(array $collectedData): array
    {
        $results = [];
        foreach ($collectedData as $className => $classInfo) {
            if (!isset($classInfo['properties']) || empty($classInfo['properties'])) {
                continue;
            }

            foreach ($classInfo['properties'] as $propName => $propData) {
                /** @var Node\Stmt\Property $propertyNode */
                $propertyNode = $propData['node'];
                $usages = $propData['usages'];

                // Check doc comment for @var
                $docComment = $propertyNode->getDocComment();
                $docVarType = null;
                if ($docComment && preg_match('/@var\s+([^\s]+)/', $docComment->getText(), $matches)) {
                    $docVarType = $matches[1];
                }

                // Check default value (like $prop = 123;)
                $defaultValueType = $this->inferDefaultValueType($propertyNode->props);

                // Now collect all usage-based types
                $allTypes = [];
                $nullChecked = false;

                // Are we only reading the property and never assigning?
                // That might yield unknown unless we have doc or default
                foreach ($usages as $usage) {
                    if ($usage['type'] === 'null') {
                        // explicit assignment of null
                        $allTypes[] = 'null';
                    } elseif ($usage['type'] !== null) {
                        $allTypes[] = $usage['type'];
                    }
                    if ($usage['kind'] === 'nullCheck') {
                        $nullChecked = true;
                    }
                }

                // Merge doc or default value type into allTypes
                if ($docVarType) {
                    $allTypes[] = $docVarType;
                }
                if ($defaultValueType) {
                    $allTypes[] = $defaultValueType;
                }

                // If property was never used (no usage info) and no docblock/default:
                if (count($usages) === 0 && !$docVarType && !$defaultValueType) {
                    $results[] = [
                        'class' => $className,
                        'property' => $propName,
                        'type' => 'unknown',
                        'reason' => 'Property is declared but never used.'
                    ];
                    continue;
                }

                // Deduplicate/clean
                $allTypes = array_unique(array_filter($allTypes));

                // If there's a dynamic property usage ( cannot gather name ), skip
                // This detection might come from the collector if we expand it to handle `$this->{$var}`
                // For brevity, ignoring that for now or treat it as "unsupported" if it occurs.

                // Attempt to determine final type
                $finalType = $this->selectFinalType($allTypes, $nullChecked);

                // Build reason
                $usageDesc = $this->buildReason($docVarType, $defaultValueType, $usages);

                $results[] = [
                    'class' => $className,
                    'property' => $propName,
                    'type' => $finalType,
                    'reason' => $usageDesc
                ];
            }
        }

        return $results;
    }

    /**
     * If the property has a default value, we try to guess the type from it.
     */
    private function inferDefaultValueType(array $propertyProps): ?string
    {
        // For each property prop (usually there's only 1 in typical usage),
        // see if there's a default e.g. $prop = 123
        foreach ($propertyProps as $prop) {
            if ($prop->default instanceof Node\Scalar\LNumber) {
                return 'int';
            } elseif ($prop->default instanceof Node\Scalar\String_) {
                return 'string';
            } elseif ($prop->default instanceof Node\Scalar\DNumber) {
                return 'float';
            } elseif ($prop->default instanceof Node\Expr\Array_) {
                return 'array';
            } elseif ($prop->default instanceof Node\Expr\ConstFetch) {
                $name = strtolower($prop->default->name->toString());
                if ($name === 'null') {
                    return 'null';
                } elseif ($name === 'true' || $name === 'false') {
                    return 'bool';
                }
            }
        }
        return null;
    }

    /**
     * Decide on a single string type from the collected usage types.
     */
    private function selectFinalType(array $allTypes, bool $nullChecked): string
    {
        // If the property is ever assigned null or is specifically checked for null, we might consider it nullable
        $hasNull = in_array('null', $allTypes, true);
        if ($hasNull) {
            // remove 'null' from $allTypes to handle combination
            $allTypes = array_diff($allTypes, ['null']);
        }

        if (count($allTypes) === 0) {
            // all we had was "null"
            return '?mixed';
        }
        if (count($allTypes) === 1) {
            // single final type, possibly with a leading ? if hasNull
            $type = reset($allTypes);
            // If multiple synonyms (int/float/string/bool/array) or classes, keep as is.
            if ($type === 'unknown') {
                $type = 'mixed';
            }

            // If docVarType was array<string>, we might just keep it as 'array' or you can parse generics more deeply
            // For demonstration, we keep it as is.

            // check for user-defined class name, etc.
            if ($hasNull || $nullChecked) {
                return '?' . $type;
            }
            return $type;
        }

        // Now we have multiple distinct types
        // Example: int, float => might unify as 'float|int' or fallback to 'mixed'
        // According to constraints, if there's too many, we might use 'mixed'
        // If using PHP < 8, union types are not allowed, so fallback to mixed
        // Here, to keep it simple, we produce a union type if it’s only 2 or 3 primary scalars
        // or fallback to 'mixed'
        // Expand this logic as needed.
        if ($this->canUseUnionTypes() && count($allTypes) <= 3) {
            // Build simple union
            $union = implode('|', $allTypes);
            if ($hasNull || $nullChecked) {
                return '?' . $union;
            }
            return $union;
        }

        // Otherwise fallback to 'mixed'
        $type = 'mixed';
        if ($hasNull || $nullChecked) {
            // In older PHP or for wide usage, simply “mixed” is enough
            return $type;
        }
        return $type;
    }

    /**
     * Simple heuristic to see if we can produce union types (PHP >= 8).
     * In real scenarios, you might parse or detect the environment version.
     */
    private function canUseUnionTypes(): bool
    {
        // For demonstration, let’s assume we're on PHP 8 or higher:
        return true;
    }

    /**
     * Build a reason string from usage data, doc, or default.
     */
    private function buildReason(?string $docType, ?string $defaultValueType, array $usages): string
    {
        $reasons = [];
        if ($docType) {
            $reasons[] = "Docblock @var suggests '{$docType}'.";
        }
        if ($defaultValueType) {
            $reasons[] = "Default value implies '{$defaultValueType}'.";
        }
        if ($usages) {
            $usageSummary = [];
            foreach ($usages as $u) {
                $desc = "{$u['kind']}";
                if ($u['type']) {
                    $desc .= " of type {$u['type']}";
                }
                $usageSummary[] = $desc;
            }
            $reasons[] = 'Used by: ' . implode(', ', $usageSummary);
        }

        return implode(' ', $reasons);
    }
}

/**
 * Main script logic: parse, collect, resolve, and output results.
 */
$file = $argv[1] ?? 'Test.php';
if (!is_file($file)) {
    fwrite(STDERR, "Usage: php TypeSuggester.php <PHP-file-to-analyze>\n");
    exit(1);
}

$code = file_get_contents($file);

// create parser
$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

// create traverser and attach collector
$traverser = new NodeTraverser();
$collector = new PropertyUsageCollector();
$traverser->addVisitor($collector);

try {
    // parse
    $ast = $parser->parse($code);

    // traverse
    $traverser->traverse($ast);

    // get usage info
    $collected = $collector->getCollectedData();

    // resolve final type suggestions
    $resolver = new TypeResolver();
    $inferredTypes = $resolver->resolve($collected);

    // output
    foreach ($inferredTypes as $info) {
        echo "#Class: {$info['class']}\n";
        echo "#Property: \${$info['property']}\n";
        echo "#Suggested Type: {$info['type']}\n";
        echo "#Reason: {$info['reason']}\n\n";
    }
} catch (Error $e) {
    echo "Parse error: {$e->getMessage()}\n";
}
