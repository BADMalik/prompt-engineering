
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
    private $results = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->classContext = $node->name->toString();
            $this->propertyUsages[$this->classContext] = [];
        } elseif ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                $propName = $prop->name->toString();
                if ($prop->type === null) { // Check if property is untyped
                    $this->propertyUsages[$this->classContext][$propName] = [];
                }
            }
        } elseif (
            $node instanceof Node\Expr\PropertyFetch
            && $node->name instanceof Node\Identifier
        ) {
            $propName = $node->name->toString();
            $this->recordPropertyUsage($propName, $node);
        } elseif (
            $node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->name instanceof Node\Identifier
        ) {
            $propName = $node->var->name->toString();
            $this->recordPropertyUsage($propName, $node);
        }
    }

    private function recordPropertyUsage($propName, $node)
    {
        if (!isset($this->propertyUsages[$this->classContext][$propName])) {
            return;
        }
        $type = $this->inferType($node);
        $this->propertyUsages[$this->classContext][$propName][] = $type;
    }

    private function inferType($node)
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return 'int';
        } elseif ($node instanceof Node\Scalar\DNumber) {
            return 'float';
        } elseif ($node instanceof Node\Scalar\String_) {
            return 'string';
        } elseif ($node instanceof Node\Expr\Array_) {
            return 'array';
        } elseif (
            $node instanceof Node\Expr\NullsafePropertyFetch
            || $node instanceof Node\Expr\NullsafeMethodCall
        ) {
            return 'mixed';
        } elseif ($node instanceof Node\Expr\NullsafeMethodCall) {
            return 'mixed';
        } elseif ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            return 'mixed';
        } elseif ($node instanceof Node\Expr\Instanceof_) {
            return (string) $node->class;
        } elseif (
            $node instanceof Node\Expr\NullsafePropertyFetch
            || $node instanceof Node\Expr\NullsafeMethodCall
        ) {
            return 'mixed';
        } elseif (
            $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Name
            && $node->name->toString() === 'isset'
        ) {
            return 'mixed';
        } elseif (
            $node instanceof Node\Expr\PropertyFetch
            && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'name'
        ) {
            return 'string';
        }
        return 'mixed';
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->finalizeTypeSuggestions();
            $this->classContext = null;
        }
    }

    private function finalizeTypeSuggestions()
    {
        foreach ($this->propertyUsages[$this->classContext] as $propName => $usages) {
            $types = array_unique($usages);
            $finalType = $this->determineFinalType($types);
            $reason = $this->buildReason($types);

            $this->results[] = [
                'class' => $this->classContext,
                'property' => $propName,
                'type' => $finalType,
                'reason' => $reason,
            ];
        }
    }

    private function determineFinalType(array $types): string
    {
        if (count($types) === 1) {
            return reset($types);
        }
        if (in_array('null', $types, true)) {
            $types = array_filter($types, function ($type) {
                return $type !== 'null';
            });
            if (count($types) === 1) {
                return '?' . reset($types);
            }
        }
        return 'mixed';
    }

    private function buildReason(array $types): string
    {
        $reasonParts = [];
        foreach ($types as $type) {
            $reasonParts[] = "Used as {$type}";
        }
        return implode(', ', $reasonParts);
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
    foreach ($visitor->results as $result) {
        echo "Class: {$result['class']}\n";
        echo "Property: \${$result['property']}\n";
        echo "Suggested Type: {$result['type']}\n";
        echo "Reason: {$result['reason']}\n\n";
    }
} catch (Error $e) {
    echo "Parse error: {$e->getMessage()}\n";
}
