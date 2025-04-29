<?php

// === Input File ===
$targetFile = $argv[1] ?? null;

if (!$targetFile || !file_exists($targetFile)) {
    echo "Usage: php infer_types.php <file.php>\n";
    exit(1);
}

$code = file_get_contents($targetFile);
$tokens = token_get_all($code);

$classes = [];
$currentClass = null;
$currentMethod = null;
$currentProperty = null;
$propertyUsageMap = [];
$visibility = null;
$hadErrors = false;

// === Basic Token Walker ===
for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];

    if (is_array($token)) {
        [$id, $text] = $token;

        // Class
        if ($id === T_CLASS && is_array($tokens[$i + 2])) {
            $currentClass = $tokens[$i + 2][1];
            $classes[$currentClass] = [];
            $propertyUsageMap[$currentClass] = [];
        }

        // Visibility
        if (in_array($id, [T_PRIVATE, T_PROTECTED, T_PUBLIC])) {
            $visibility = strtolower($text);
        }

        // Property Declaration
        if ($id === T_VARIABLE && $tokens[$i - 1] !== T_FUNCTION && isset($classes[$currentClass])) {
            $propName = substr($text, 1);
            if (!isset($classes[$currentClass][$propName])) {
                $classes[$currentClass][$propName] = [
                    'visibility' => $visibility ?? 'public',
                    'usages' => [],
                ];
            }
        }

        // Assignment or usage detection: $this->prop = value;
        if (
            $id === T_VARIABLE && $text === '$this'
            && isset($tokens[$i + 1]) && $tokens[$i + 1] === '->'
            && is_array($tokens[$i + 2])
        ) {
            $propName = $tokens[$i + 2][1];
            $assignmentType = null;

            // Look ahead for assignment
            for ($j = $i + 3; $j < min($i + 10, count($tokens)); $j++) {
                if ($tokens[$j] === '=') {
                    $value = $tokens[$j + 1] ?? null;

                    if (is_array($value)) {
                        switch ($value[0]) {
                            case T_CONSTANT_ENCAPSED_STRING:
                                $assignmentType = 'string';
                                break;
                            case T_LNUMBER:
                                $assignmentType = 'int';
                                break;
                            case T_DNUMBER:
                                $assignmentType = 'float';
                                break;
                            case T_ARRAY:
                                $assignmentType = 'array';
                                break;
                            case T_STRING:
                                if (strtolower($value[1]) === 'true' || strtolower($value[1]) === 'false') {
                                    $assignmentType = 'bool';
                                } elseif (strtolower($value[1]) === 'null') {
                                    $assignmentType = 'null';
                                } else {
                                    $assignmentType = 'mixed';
                                }
                                break;
                            default:
                                $assignmentType = 'mixed';
                        }
                    } elseif (is_string($value)) {
                        if ($value === 'null') {
                            $assignmentType = 'null';
                        }
                    }
                    break;
                }
            }

            if ($assignmentType) {
                $propertyUsageMap[$currentClass][$propName][] = $assignmentType;
            }
        }
    }
}

// === Inference Output ===
foreach ($classes as $className => $props) {
    echo "Class: $className\n";

    foreach ($props as $prop => $meta) {
        $usages = $propertyUsageMap[$className][$prop] ?? [];

        if (empty($usages)) {
            $type = 'unknown';
            $reason = 'No assignment found in class';
            $hadErrors = true;
        } else {
            $unique = array_unique($usages);
            if (count($unique) === 1) {
                $type = $unique[0];
                $reason = "Assigned only as {$type}";
            } elseif (in_array('null', $unique)) {
                $nonNulls = array_filter($unique, fn($t) => $t !== 'null');
                if (count($nonNulls) === 1) {
                    $type = '?' . reset($nonNulls);
                    $reason = "Assigned {$nonNulls[0]} and also null";
                } else {
                    $type = implode('|', $unique);
                    $reason = "Multiple types including null";
                    $hadErrors = true;
                }
            } else {
                $type = implode('|', $unique);
                $reason = "Multiple assignment types detected";
                $hadErrors = true;
            }
        }

        echo "- \${$prop} : {$type} ({$reason})\n";
    }

    echo str_repeat('-', 40) . "\n";
}

// === Final Confirmation ===
if (!$hadErrors) {
    echo "Everything looks good. No errors detected.\n";
}
