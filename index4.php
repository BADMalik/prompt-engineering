<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Data structure to collect information about each property.
 */
class PropertyUsage
{
    public bool $hasExplicitType = false;
    public bool $seenNull = false;
    public bool $unsupportedDynamic = false;
    public array $typesSeen = [];     // e.g. ['int', 'string']
    public bool $neverUsed = true;    // remains true until we see any usage
    public ?string $docBlockType = null;
    public ?string $defaultValueType = null;
}

/**
 * This visitor will:
 *  1. Detect all class properties and mark whether they are typed or not.
 *  2. Track usage ($this->prop) for assignments, method calls, coalescing, etc.
 *  3. Store each usage's inferred type in a structure to allow final inference.
 */
class TypeInferenceVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array<string, PropertyUsage>> */
    private array $properties = [];
    /** @var string|null Stores the name of the currently visited class */
    private ?string $currentClass = null;

    /** @var array<int, mixed> Final results to be printed after traversal */
    public array $results = [];

    public function enterNode(Node $node)
    {
        // Track when we enter a class
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name ? $node->name->toString() : null;
        }

        // Record property declarations
        if ($node instanceof Node\Stmt\Property && $this->currentClass !== null) {
            foreach ($node->props as $prop) {
                $propName = $prop->name->toString();
                // Initialize usage info if not present
                if (!isset($this->properties[$this->currentClass][$propName])) {
                    $this->properties[$this->currentClass][$propName] = new PropertyUsage();
                }
                $propUsage = $this->properties[$this->currentClass][$propName];

                // If property is typed, mark it as havingExplicitType so we skip suggestions
                if ($node->type !== null) {
                    $propUsage->hasExplicitType = true;
                }

                // Try to infer default value type if any
                if ($prop->default instanceof Node\Expr) {
                    $propUsage->defaultValueType = $this->inferTypeFromExpr($prop->default);
                }

                // Check for doc comments @var
                $docComment = $node->getDocComment();
                if ($docComment && preg_match('/@var\s+([^\s]+)/', $docComment->getText(), $matches)) {
                    // Rough parse: just store the raw doc type
                    $propUsage->docBlockType = $matches[1];
                }
            }
        }

        // Look for usage of $this->someProperty
        //  e.g. in Assign($this->someProp, expr), or method calls, etc.
        if ($node instanceof Node\Expr\PropertyFetch) {
            // confirm it's $this->...
            if (
                $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && is_string($node->name)
            ) {
                $propName = $node->name;
                // Mark usage
                $this->trackPropertyUsage($propName, $node);
            } elseif (
                $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && !$node->name instanceof Node\Identifier
            ) {
                // $this->{$something} dynamic property usage
                $this->markDynamicUnsupported();
            }
        }

        // Also watch for $this->... in PropertyAssign nodes: $this->prop = <expr>
        if ($node instanceof Node\Expr\Assign) {
            // left side
            if (
                $node->var instanceof Node\Expr\PropertyFetch
                && $node->var->var instanceof Node\Expr\Variable
                && $node->var->var->name === 'this'
                && is_string($node->var->name)
            ) {
                $propName = $node->var->name;
                $this->trackPropertyUsage($propName, $node->var);

                // Also record the type from the right-hand side
                $assignedType = $this->inferTypeFromExpr($node->expr);
                $this->recordType($propName, $assignedType);
                if ($assignedType === 'null') {
                    $this->markNullSeen($propName);
                }
            } elseif (
                $node->var instanceof Node\Expr\PropertyFetch
                && $node->var->var instanceof Node\Expr\Variable
                && $node->var->var->name === 'this'
                && !$node->var->name instanceof Node\Identifier
            ) {
                // $this->{$something} = ...
                $this->markDynamicUnsupported();
            }
        }

        // Check for null coalescing $this->prop ?? ...
        if ($node instanceof Node\Expr\NullsafePropertyFetch) {
            // $this->prop?->something is at least an indication we might have null
            if (
                $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && is_string($node->name)
            ) {
                $this->trackPropertyUsage($node->name, $node);
                $this->markNullSeen($node->name);
            }
        }

        // Check for isset($this->prop)
        if ($node instanceof Node\Expr\Isset_) {
            foreach ($node->vars as $var) {
                if (
                    $var instanceof Node\Expr\PropertyFetch
                    && $var->var instanceof Node\Expr\Variable
                    && $var->var->name === 'this'
                    && is_string($var->name)
                ) {
                    $this->trackPropertyUsage($var->name, $var);
                    // If code checks isset, property might be null
                    $this->markNullSeen($var->name);
                }
            }
        }

        // Check for coalesce: ($this->prop ?? <expr>)
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            if (
                $node->left instanceof Node\Expr\PropertyFetch
                && $node->left->var instanceof Node\Expr\Variable
                && $node->left->var->name === 'this'
                && is_string($node->left->name)
            ) {
                $propName = $node->left->name;
                $this->trackPropertyUsage($propName, $node->left);
                $this->markNullSeen($propName);
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        // When exiting a class, reset currentClass
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        }
        return null;
    }

    /**
     * After we finish traversing, we analyze the collected data and produce results.
     */
    public function afterTraverse(array $nodes)
    {
        foreach ($this->properties as $className => $props) {
            foreach ($props as $propName => $usage) {
                // If the property is already typed, skip
                if ($usage->hasExplicitType) {
                    continue;
                }

                // Basic heuristics:
                //  1. If dynamic usage was flagged, mark as dynamic (unsupported)
                //  2. If never used, type is unknown
                //  3. Otherwise unify docBlock, default, usage-based types
                //
                //  Then decide if it is nullable (seenNull)
                //  If multiple non-null types -> union or "mixed"

                $finalType = 'unknown';
                $reason = '';

                if ($usage->unsupportedDynamic) {
                    $finalType = 'dynamic (unsupported)';
                    $reason = 'Property used via dynamic name: $this->{$name}';
                } elseif ($usage->neverUsed) {
                    $finalType = 'unknown';
                    $reason = 'Property is declared but never used';
                } else {
                    // Collect all usage-based types
                    $allTypes = array_unique($usage->typesSeen);

                    // Incorporate docBlock type
                    if ($usage->docBlockType !== null) {
                        $allTypes[] = $usage->docBlockType;
                        $allTypes = array_unique($allTypes);
                    }

                    // Incorporate defaultValue type
                    if ($usage->defaultValueType !== null && $usage->defaultValueType !== 'null') {
                        $allTypes[] = $usage->defaultValueType;
                        $allTypes = array_unique($allTypes);
                    }

                    if (empty($allTypes)) {
                        // We found usage but only null usage?
                        if ($usage->seenNull) {
                            $finalType = '?mixed';
                            $reason = 'Only null usage found, defaulting to ?mixed';
                        } else {
                            $finalType = 'mixed';
                            $reason = 'No definite usage type found';
                        }
                    } else {
                        // If there's more than one distinct type (excluding 'null'), attempt union or fallback to 'mixed'
                        $nonNullTypes = array_diff($allTypes, ['null']);
                        // If no non-null types remain, everything was "null"
                        if (count($nonNullTypes) === 0) {
                            // All usage was null
                            $finalType = '?mixed';
                            $reason = 'All usage was null.';
                        } elseif (count($nonNullTypes) === 1) {
                            // Single real type
                            $mainType = reset($nonNullTypes);
                            // If there's any chance of null
                            if ($usage->seenNull) {
                                $finalType = '?' . $mainType;
                                $reason = "Detected single type $mainType and null usage";
                            } else {
                                $finalType = $mainType;
                                $reason = "Detected single type $mainType from usage";
                            }
                        } else {
                            // More than one distinct type
                            // Decide whether to try union or fallback to mixed
                            // For PHP7.4 we don't have real union types natively as a typed property,
                            // so you might choose "mixed" (or implement a version check).
                            // Here we do a naive fallback to "mixed".
                            // If you are targeting PHP 8.0+, you could try generating "int|string", etc.
                            $finalType = 'mixed';
                            $reason = 'Multiple distinct types detected: ' . implode(',', $nonNullTypes);
                            if ($usage->seenNull) {
                                $finalType = '?' . $finalType;
                            }
                        }
                    }
                }

                $this->results[] = [
                    'class'    => $className,
                    'property' => $propName,
                    'type'     => $finalType,
                    'reason'   => $reason
                ];
            }
        }
    }

    /**
     * Mark that a property was referenced, so it is used.
     */
    private function trackPropertyUsage(string $propName, Node $node): void
    {
        if ($this->currentClass === null) {
            return;
        }
        if (!isset($this->properties[$this->currentClass][$propName])) {
            $this->properties[$this->currentClass][$propName] = new PropertyUsage();
        }
        $this->properties[$this->currentClass][$propName]->neverUsed = false;
    }

    /**
     * Mark that a property can be null.
     */
    private function markNullSeen(string $propName): void
    {
        if ($this->currentClass === null) {
            return;
        }
        $this->properties[$this->currentClass][$propName]->seenNull = true;
    }

    /**
     * Record a new (non-null) type usage for the current property.
     */
    private function recordType(string $propName, string $type): void
    {
        if ($this->currentClass === null) {
            return;
        }
        if (!isset($this->properties[$this->currentClass][$propName])) {
            $this->properties[$this->currentClass][$propName] = new PropertyUsage();
        }
        if ($type !== 'null') {
            $this->properties[$this->currentClass][$propName]->typesSeen[] = $type;
        }
    }

    /**
     * Mark that we encountered a dynamic property usage ($this->{$expr}).
     */
    private function markDynamicUnsupported(): void
    {
        if ($this->currentClass === null) {
            return;
        }
        // We don't know which property name this references,
        // so mark all untyped props in this class as dynamic = true
        foreach ($this->properties[$this->currentClass] as $propName => $usage) {
            if (!$usage->hasExplicitType) {
                $usage->unsupportedDynamic = true;
            }
        }
    }

    /**
     * Attempt a simple heuristic for an expression's type.
     */
    private function inferTypeFromExpr(Node\Expr $expr): string
    {
        // Int literal
        if ($expr instanceof Node\Scalar\LNumber) {
            return 'int';
        }
        // Float literal
        if ($expr instanceof Node\Scalar\DNumber) {
            return 'float';
        }
        // String literal
        if ($expr instanceof Node\Scalar\String_) {
            return 'string';
        }
        // Boolean literal
        if ($expr instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }
            if ($name === 'null') {
                return 'null';
            }
        }

        // Array creation
        if ($expr instanceof Node\Expr\Array_) {
            return 'array';
        }

        // New object
        if ($expr instanceof Node\Expr\New_) {
            if ($expr->class instanceof Node\Name) {
                // e.g. new Foo() => "Foo"
                return $expr->class->toString();
            }
            // can't tell
            return 'object';
        }

        // Null coalesce or null safe etc. might be unknown or indicate possible null
        // but we'll handle that from usage directly
        // Return "unknown" as a fallback
        return 'unknown';
    }
}

/**
 * ------------------------
 *   SETUP + EXECUTION
 * ------------------------
 */

$inputFile = $argv[1] ?? 'Test.php';
$code = file_get_contents($inputFile);
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser = new NodeTraverser();
$visitor = new TypeInferenceVisitor();
$traverser->addVisitor($visitor);

try {
    $ast = $parser->parse($code);
    $traverser->traverse($ast);

    // After traversal, the visitor has results in $visitor->results
    foreach ($visitor->results as $result) {
        echo "#Class: {$result['class']}\n";
        echo "#Property: \${$result['property']}\n";
        echo "#Suggested Type: {$result['type']}\n";
        echo "#Reason: {$result['reason']}\n\n";
    }
} catch (Error $e) {
    echo "Parse error: {$e->getMessage()}\n";
}
