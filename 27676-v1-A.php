<?php

/**
 * Type Mismatch Detector (Standalone)
 *
 * A basic static analysis tool that checks for type mismatches in function calls.
 * - Uses token_get_all() instead of a full AST.
 * - Supports only global functions (no methods or closures).
 * - Handles basic variable assignments (int, string).
 * - Assumes one value per variable (no reassignment flow).
 * - Ignores complex expressions (e.g. ($a + 1)).
 * - No union or nullable type support.
 * - No object, array, or class type detection.
 * - Doesn't process class methods or namespaces.
 * - No return type checking or default parameters handling.
 * - No variadic arguments (...) support.
 * - No error recovery for invalid tokens.
 * - No real scoping or context-awareness.
 * - No recursion or nested function analysis.
 * - Flat file structure; no modular architecture.
 * - No use of PHP Reflection, SPL, or Composer autoload.
 * - No config, CLI options, or verbosity levels.
 * - Single-file analysis only (no multi-file support).
 * - Doesn't distinguish between global and local assignments.
 * - No compatibility with tools like PHPStan or Psalm.
 *
 * Areas Improved from Original:
 * 1. Added basic argument checking for the input file.
 * 2. Added boundary checks before accessing adjacent tokens.
 * 3. Added safeguard while parsing function arguments to prevent infinite loops.
 * 4. Maintained constraints without adding unsupported features.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " <path-to-php-file>\n");
    exit(1);
}

$filePath = $argv[1];
if (!is_readable($filePath)) {
    fwrite(STDERR, "Error: Cannot read file '{$filePath}'.\n");
    exit(1);
}

$code = file_get_contents($filePath);
$tokens = token_get_all($code);

// --- Storage ---
$functions = [];     // Maps functionName -> [ '$argName' => type, ... ]
$assignments = [];   // Maps '$var' -> type
$errors = [];        // Stores string error messages
$currentFunction = null;
$functionArgs = [];
$inFunction = false;

// --- Parse tokens ---
for ($i = 0, $count = count($tokens); $i < $count; $i++) {
    $token = $tokens[$i];

    if (is_array($token)) {
        [$type, $value] = $token;

        // Detect start of function
        if ($type === T_FUNCTION) {
            // Next token for function name might be a space, &, or T_STRING
            // So we move forward until we find a potential T_STRING (function name)
            $fnIndex = $i + 1;
            while (isset($tokens[$fnIndex]) && (!is_array($tokens[$fnIndex]) || $tokens[$fnIndex][0] !== T_STRING)) {
                $fnIndex++;
            }
            if (!isset($tokens[$fnIndex]) || !is_array($tokens[$fnIndex])) {
                // Safeguard if no valid function name found
                continue;
            }

            $currentFunction = $tokens[$fnIndex][1];
            $inFunction = true;
            $functionArgs = [];

            // Parse function arguments until opening brace '{'
            // Start from next token after function name
            $j = $fnIndex + 1;
            while (isset($tokens[$j]) && $tokens[$j] !== '{') {
                // If we find a variable, we try to see if there's a type before it
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                    $argName = $tokens[$j][1];
                    $argType = null;

                    // Check two tokens back for a possible T_STRING typehint
                    if (
                        isset($tokens[$j - 2]) &&
                        is_array($tokens[$j - 2]) &&
                        $tokens[$j - 2][0] === T_STRING
                    ) {
                        $argType = $tokens[$j - 2][1];
                    }

                    $functionArgs[$argName] = $argType ?? 'mixed';
                }
                $j++;
                if ($j >= $count) {
                    // Safeguard against going out of bounds
                    break;
                }
            }

            $functions[$currentFunction] = $functionArgs;
            continue;
        }

        // Detect variable assignment in a function
        if (
            $type === T_VARIABLE &&
            $inFunction &&
            // Check boundaries before accessing adjacent tokens
            isset($tokens[$i + 1]) &&
            !is_array($tokens[$i + 1]) &&
            $tokens[$i + 1] === '='
        ) {
            $varName = $value;
            // Next token after '=' sign
            if (isset($tokens[$i + 2]) && is_array($tokens[$i + 2])) {
                $valueToken = $tokens[$i + 2];
                if ($valueToken[0] === T_LNUMBER) {
                    $assignments[$varName] = 'int';
                } elseif ($valueToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $assignments[$varName] = 'string';
                }
            }
            continue;
        }

        // Detect a function call by a T_STRING whose value corresponds to a known function
        if ($type === T_STRING && isset($functions[$value])) {
            $funcName = $value;
            // Check if next token is '(' to confirm it's a call
            if (isset($tokens[$i + 1]) && $tokens[$i + 1] === '(') {
                // Gather arguments in the call
                $callArgs = [];
                $j = $i + 2;
                while (isset($tokens[$j]) && $tokens[$j] !== ')') {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $callArgs[] = $tokens[$j][1];
                    }
                    $j++;
                    if ($j >= $count) {
                        break; // safeguard from out-of-bounds
                    }
                }

                // Compare inferred types with expected types
                $expectedArgs = array_values($functions[$funcName]);
                foreach ($callArgs as $k => $arg) {
                    $inferredType = $assignments[$arg] ?? 'mixed';
                    $expected = $expectedArgs[$k] ?? 'mixed';

                    if ($expected !== 'mixed' && $expected !== $inferredType) {
                        $errors[] = "Type mismatch in call to `{$funcName}`: expected {$expected} but got {$inferredType} for argument {$arg}";
                    }
                }
            }
        }
    }

    // Detect end of function block
    if ($token === '}') {
        $inFunction = false;
    }
}

// --- Output ---
echo "Function Signatures:\n";
print_r($functions);

echo "\nAssignments:\n";
print_r($assignments);

echo "\nType Mismatches:\n";
if (empty($errors)) {
    echo "No mismatches found.\n";
} else {
    foreach ($errors as $err) {
        echo "❌ $err\n";
    }
}
