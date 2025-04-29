<?php

$code = file_get_contents($argv[1] ?? 'sample.php');
$tokens = token_get_all($code);

$functions = [];
$assignments = [];
$errors = [];
$lineMap = [];

$currentFunction = null;
$inFunction = false;
$braceDepth = 0;

for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];

    if (is_array($token)) {
        [$type, $value, $line] = $token;
        $lineMap[$i] = $line;

        // Parse function definition
        if ($type === T_FUNCTION) {
            $nameIndex = $i + 2;
            while ($tokens[$nameIndex][0] !== T_STRING) $nameIndex++;
            $funcName = $tokens[$nameIndex][1];

            $argTypes = [];
            $argNames = [];

            $j = $nameIndex + 1;
            while ($tokens[$j] !== '{') {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $argName = $tokens[$j][1];
                    $argType = 'mixed';

                    for ($k = $j - 1; $k >= 0; $k--) {
                        if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_STRING, T_NAME_QUALIFIED])) {
                            $argType = $tokens[$k][1];
                            break;
                        }
                        if ($tokens[$k] === ',' || $tokens[$k] === '(') break;
                    }

                    $argNames[] = $argName;
                    $argTypes[] = $argType;
                }
                $j++;
            }

            $functions[$funcName] = array_combine($argNames, $argTypes);
        }

        // Detect variable assignments outside functions
        if ($type === T_VARIABLE && $tokens[$i + 1] === '=') {
            $varName = $value;
            $rhs = $tokens[$i + 2];

            if (is_array($rhs)) {
                if ($rhs[0] === T_LNUMBER) {
                    $assignments[$varName] = 'int';
                } elseif ($rhs[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $assignments[$varName] = 'string';
                }
            }
        }

        // Detect function calls
        if ($type === T_STRING && isset($functions[$value])) {
            $funcName = $value;
            if ($tokens[$i + 1] === '(') {
                $argValues = [];
                $argStart = $i + 2;
                $parenDepth = 1;
                $argBuffer = '';
                $cur = $argStart;

                while ($parenDepth > 0 && $cur < count($tokens)) {
                    $tok = $tokens[$cur];
                    if ($tok === '(') $parenDepth++;
                    if ($tok === ')') $parenDepth--;

                    if ($parenDepth > 0) {
                        if (is_array($tok)) {
                            $argBuffer .= $tok[1];
                        } else {
                            $argBuffer .= $tok;
                        }
                    }
                    $cur++;
                }

                // Split top-level args (very basic, assumes no commas in strings)
                $rawArgs = array_map('trim', explode(',', $argBuffer));
                $expectedTypes = array_values($functions[$funcName]);
                $paramNames = array_keys($functions[$funcName]);

                foreach ($rawArgs as $k => $arg) {
                    $type = 'mixed';

                    if (preg_match('/^["\'].*["\']$/', $arg)) {
                        $type = 'string';
                    } elseif (is_numeric($arg)) {
                        $type = 'int';
                    } elseif (isset($assignments[$arg])) {
                        $type = $assignments[$arg];
                    }

                    $expected = $expectedTypes[$k] ?? 'mixed';
                    $param = $paramNames[$k] ?? "arg$k";

                    if ($expected !== 'mixed' && $expected !== $type) {
                        $errors[] = "❌ Type mismatch in call to `$funcName`: expected $expected but got $type for argument $param (line {$lineMap[$i]})";
                    }
                }

                // Extra/missing argument detection
                if (count($rawArgs) < count($expectedTypes)) {
                    $errors[] = "❌ Too few arguments in call to `$funcName` (line {$lineMap[$i]})";
                } elseif (count($rawArgs) > count($expectedTypes)) {
                    $errors[] = "❌ Too many arguments in call to `$funcName` (line {$lineMap[$i]})";
                }
            }
        }
    }
}

echo "# Type Mismatches\n";
if (empty($errors)) {
    echo "No mismatches found.\n";
} else {
    foreach ($errors as $err) {
        echo "$err\n";
    }
}
