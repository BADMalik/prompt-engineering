<?php

$code = file_get_contents($argv[1] ?? 'sample.php');
$tokens = token_get_all($code);

$functions = [];
$assignments = [];
$errors = [];
$lineMap = [];

function resolveType($arg, $assignments)
{
    $arg = trim($arg);
    if (preg_match('/^["\'].*["\']$/', $arg)) return 'string';
    if (is_numeric($arg)) return 'int';
    if (strtolower($arg) === 'null') return 'null';
    if (isset($assignments[$arg])) return $assignments[$arg];
    return 'mixed';
}

function matchType($expected, $actual)
{
    $expected = explode('|', str_replace(['?', '...'], '', $expected));
    return in_array($actual, $expected) || in_array('mixed', $expected);
}

// Extract function definitions
for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];

    if (is_array($token)) {
        $lineMap[$i] = $token[2];
        if ($token[0] === T_FUNCTION) {
            // Get function name
            $j = $i + 2;
            while ($tokens[$j][0] !== T_STRING) $j++;
            $funcName = $tokens[$j][1];

            // Get argument types
            $argTypes = [];
            $k = $j;
            while ($tokens[$k] !== '{') {
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE) {
                    $argName = $tokens[$k][1];
                    $argType = 'mixed';

                    for ($m = $k - 1; $m >= 0; $m--) {
                        if (is_array($tokens[$m]) && in_array($tokens[$m][0], [T_STRING, T_NAME_QUALIFIED])) {
                            $argType = $tokens[$m][1];
                            break;
                        } elseif ($tokens[$m] === '|') {
                            // union type continues
                            continue;
                        } elseif ($tokens[$m] === '?' || $tokens[$m] === '...') {
                            $argType = $tokens[$m] . $argType;
                        } elseif ($tokens[$m] === ',' || $tokens[$m] === '(') {
                            break;
                        }
                    }
                    $argTypes[$argName] = $argType;
                }
                $k++;
            }

            $functions[$funcName] = $argTypes;
        }

        // Track variable assignments (overwrite allowed)
        if ($token[0] === T_VARIABLE && $tokens[$i + 1] === '=') {
            $varName = $token[1];
            $rhs = $tokens[$i + 2];
            if (is_array($rhs)) {
                if ($rhs[0] === T_LNUMBER) {
                    $assignments[$varName] = 'int';
                } elseif ($rhs[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $assignments[$varName] = 'string';
                }
            } elseif (is_string($rhs) && strtolower($rhs) === 'null') {
                $assignments[$varName] = 'null';
            }
        }
    }

    // Function call checker
    if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && isset($functions[$tokens[$i][1]])) {
        $funcName = $tokens[$i][1];
        $argTypes = $functions[$funcName];
        if ($tokens[$i + 1] === '(') {
            $args = [];
            $buffer = '';
            $parenDepth = 1;
            $j = $i + 2;
            while ($parenDepth > 0 && $j < count($tokens)) {
                $tok = $tokens[$j];
                if ($tok === '(') $parenDepth++;
                elseif ($tok === ')') $parenDepth--;

                if ($parenDepth > 0) {
                    if (is_array($tok)) {
                        $buffer .= $tok[1];
                    } else {
                        $buffer .= $tok;
                    }
                }
                $j++;
            }
            $argList = array_map('trim', explode(',', $buffer));
            $paramNames = array_keys($argTypes);
            $expectedTypes = array_values($argTypes);

            foreach ($argList as $index => $argExpr) {
                $argExpr = trim($argExpr);
                $actualType = resolveType($argExpr, $assignments);
                $expectedType = $expectedTypes[$index] ?? null;
                $param = $paramNames[$index] ?? "arg$index";

                if ($expectedType !== null && !matchType($expectedType, $actualType)) {
                    $errors[] = "❌ Type mismatch in call to `$funcName`: expected $expectedType but got $actualType for argument $param (line {$lineMap[$i]})";
                }
            }

            if (count($argList) > count($expectedTypes)) {
                $errors[] = "❌ Too many arguments in call to `$funcName` (line {$lineMap[$i]})";
            }
        }
    }
}

echo "# Type Mismatches\n";
if (empty($errors)) {
    echo "✅ No mismatches found.\n";
} else {
    foreach ($errors as $e) {
        echo "$e\n";
    }
}
