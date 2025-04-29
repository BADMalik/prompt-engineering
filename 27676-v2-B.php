<?php

/**
 * # PHP Type Mismatch Detector
 * 
 * This script detects type mismatches in PHP code using token analysis.
 * 
 * ## Constraints:
 * - Uses `token_get_all()` instead of a full AST.
 * - Supports only global functions (no methods or closures).
 * - Handles basic variable assignments (`int`, `string`).
 * - Assumes one value per variable (no reassignment flow).
 * - Ignores expression complexity (e.g., `($a + 1)`).
 * - No union or nullable type support.
 * - No object, array, or class type detection.
 * - Doesn't process class methods or namespaces.
 * - No return type checking.
 * - No default parameters handling.
 * - No variadic arguments (`...$args`) support.
 * - No error recovery for invalid tokens.
 * - No real scoping or context-awareness.
 * - No recursion or nested function analysis.
 * - Flat file structure; no modular architecture.
 * - No use of PHP Reflection, SPL, or Composer autoload.
 * - No config, CLI options, or verbosity levels.
 * - Can't analyze across multiple files.
 * - Doesn't distinguish between global and local assignments.
 * - No compatibility with tools like PHPStan or Psalm.
 */

$code = file_get_contents($argv[1] ?? 'sample.php');
$tokens = token_get_all($code);

// --- Storage ---
$functions = [];
$assignments = [];
$errors = [];
$currentFunction = null;
$functionArgs = [];
$inFunction = false;

// --- Parse tokens ---
for ($i = 0; $i < count($tokens); $i++) {
    $token = $tokens[$i];

    if (is_array($token)) {
        [$type, $value] = $token;

        if ($type === T_FUNCTION) {
            $nameToken = $tokens[$i + 2];
            $currentFunction = $nameToken[1];
            $inFunction = true;
            $functionArgs = [];

            // Parse function arguments
            $j = $i + 3;
            while ($tokens[$j] !== '{') {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $argName = $tokens[$j][1];
                    $argType = null;

                    if (is_array($tokens[$j - 2]) && $tokens[$j - 2][0] === T_STRING) {
                        $argType = $tokens[$j - 2][1];
                    }

                    $functionArgs[$argName] = $argType ?? 'mixed';
                }
                $j++;
            }

            $functions[$currentFunction] = $functionArgs;
        }

        if ($type === T_VARIABLE && $inFunction && $tokens[$i + 1] === '=') {
            $varName = $value;
            $valueToken = $tokens[$i + 2];
            if (is_array($valueToken)) {
                if ($valueToken[0] === T_LNUMBER) {
                    $assignments[$varName] = 'int';
                } elseif ($valueToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $assignments[$varName] = 'string';
                }
            }
        }

        if ($type === T_STRING && isset($functions[$value])) {
            $funcName = $value;
            if ($tokens[$i + 1] === '(') {
                $callArgs = [];
                $j = $i + 2;
                while ($tokens[$j] !== ')') {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $callArgs[] = $tokens[$j][1];
                    }
                    $j++;
                }

                $expectedArgs = array_values($functions[$funcName]);
                foreach ($callArgs as $k => $arg) {
                    $inferredType = $assignments[$arg] ?? 'mixed';
                    $expected = $expectedArgs[$k] ?? 'mixed';

                    if ($expected !== 'mixed' && $expected !== $inferredType) {
                        $errors[] = "Type mismatch in call to `$funcName`: expected $expected but got $inferredType for argument $arg";
                    }
                }
            }
        }
    }

    if ($token === '}') {
        $inFunction = false;
    }
}

// --- Output ---
echo "## Function Signatures:\n";
print_r($functions);

echo "\n## Assignments:\n";
print_r($assignments);

echo "\n## Type Mismatches:\n";
if (empty($errors)) {
    echo "No mismatches found.\n";
} else {
    foreach ($errors as $err) {
        echo "❌ $err\n";
    }
}
