<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Property;

class TypeInferenceVisitor extends NodeVisitorAbstract
{
    private $classContext = null;
    private $propertyUsages = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->classContext = $node->name->toString();
            $this->propertyUsages[$this->classContext] = [];
        } elseif ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                $propName = $prop->name->toString();
                $this->propertyUsages[$this->classContext][$propName] = [];
            }
        } elseif ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $propName = $node->name->toString();
            $this->trackUsage($propName, $node);
        } elseif ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\PropertyFetch) {
            $propName = $node->var->name->toString();
            $this->trackAssignment($propName, $node->expr);
        }
    }

    private function trackUsage($propName, $node)
    {
        if (!isset($this->propertyUsages[$this->classContext][$propName])) {
            return;
        }
        $this->propertyUsages[$this->classContext][$propName]['usages'][] = $node;
    }

    private function trackAssignment($propName, $expr)
    {
        if (!isset($this->propertyUsages[$this->classContext][$propName])) {
            return;
        }
        $this->propertyUsages[$this->classContext][$propName]['assignments'][] = $expr;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->finalizeTypeSuggestions($node);
            $this->classContext = null;
        }
    }

    private function finalizeTypeSuggestions(Node\Stmt\Class_ $classNode)
    {
        $className = $classNode->name->toString();
        foreach ($classNode->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $propName = $prop->name->toString();

                // Skip if already typed
                if ($property->type !== null) {
                    continue;
                }

                if (!isset($this->propertyUsages[$className][$propName])) {
                    $this->propertyUsages[$className][$propName] = [
                        'types' => [],
                        'reason' => 'Property is declared but never used.'
                    ];
                    continue;
                }

                $usages = $this->propertyUsages[$className][$propName];
                $types = $this->inferTypesFromUsages($usages);

                $finalType = $this->determineFinalType($types);
                $reason = $this->buildReason($usages, $types);

                $this->propertyUsages[$className][$propName] = [
                    'type' => $finalType,
                    'reason' => $reason
                ];
            }
        }

        foreach ($this->propertyUsages[$className] as $propName => $data) {
            echo "Class: {$className}\n";
            echo "Property: \${$propName}\n";
            echo "Suggested Type: {$data['type']}\n";
            echo "Reason: {$data['reason']}\n\n";
        }
    }

    private function inferTypesFromUsages(array $usages): array
    {
        $types = [];
        foreach ($usages['assignments'] ?? [] as $assignment) {
            $type = $this->getTypeFromNode($assignment);
            if ($type) {
                $types[] = $type;
            }
        }
        return $types;
    }

    private function getTypeFromNode($node): ?string
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return 'int';
        } elseif ($node instanceof Node\Scalar\DNumber) {
            return 'float';
        } elseif ($node instanceof Node\Scalar\String_) {
            return 'string';
        } elseif ($node instanceof Node\Expr\Array_) {
            return 'array';
        } elseif ($node instanceof Node\Expr\NullsafePropertyFetch || $node instanceof Node\Expr\PropertyFetch) {
            return 'object';
        } elseif ($node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\MethodCall) {
            return 'object';
        } elseif ($node instanceof Node\Expr\NullsafePropertyFetch) {
            return 'object';
        } elseif ($node instanceof Node\Expr\ConstFetch && $node->name->toString() === 'null') {
            return 'null';
        }
        return null;
    }

    private function determineFinalType(array $types): string
    {
        $uniqueTypes = array_unique($types);
        if (count($uniqueTypes) === 1) {
            return $uniqueTypes[0];
        }

        if (in_array('null', $uniqueTypes)) {
            return '?' . $this->getMostFrequentType($uniqueTypes);
        }

        return 'mixed';
    }

    private function getMostFrequentType(array $types): string
    {
        $frequency = array_count_values($types);
        arsort($frequency);
        return array_key_first($frequency);
    }

    private function buildReason(array $usages, array $types): string
    {
        if (empty($usages['assignments'])) {
            return 'Property is declared but never assigned.';
        }

        $reason = 'Inferred from assignments: ';
        if (count($types) === 1) {
            $reason .= "consistently assigned as {$types[0]}.";
        } else {
            $reason .= "multiple types detected: " . implode(', ', $types) . ".";
        }

        return $reason;
    }
}

// Setup
$code = file_get_contents($argv[1] ?? 'Test.php');
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$traverser = new NodeTraverser;
$visitor = new TypeInferenceVisitor;
$traverser->addVisitor($visitor);

try {
    $ast = $parser->parse($code);
    $traverser->traverse($ast);
} catch (Error $e) {
    echo "Parse error: {$e->getMessage()}\n";
}
