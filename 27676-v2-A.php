<?php

/**
 * PHP Type Mismatch Detector
 *
 * Parses a given PHP file with token_get_all() to detect simple type mismatches
 * in function calls. Only handles:
 *   - Global functions
 *   - Basic int/string variable assignments
 *   - Single assignment per variable
 * 
 * Usage:
 *   ./detect-types.php path/to/code.php
 * 
 * If no path is provided, defaults to 'sample.php'.
 */

// Retrieve code from file or default sample.php
$file     = $argv[1] ?? 'sample.php';
$code     = @file_get_contents($file);
if ($code === false) {
    fwrite(STDERR, "Error: Cannot read file '$file'.\n");
    exit(1);
}

// Tokenize the code
$tokens = token_get_all($code);

// Structures for gathering data
$functions     = [];  // [funcName => [ '$argName' => 'type', ... ], ...]
$assignments   = [];  // ['$varName' => 'type']
$errors        = [];
$currentFunc   = null;
$functionArgs  = [];
$inFunction    = false;

// Parse tokens
for ($i = 0, $count = count($tokens); $i < $count; $i++) {
    $token = $tokens[$i];

    // Tokens can be arrays (token type, text, line) or single-character strings
    if (is_array($token)) {
        list($type, $value) = $token;

        // Detect function definition
        if ($type === T_FUNCTION) {
            // The next non-whitespace token should be T_STRING for the function name
            // e.g. function foo() { ... }
            // "function" -> "("whitespace"?) -> T_STRING
            // Typically at i+1 is whitespace/comment, i+2 is function name
            $j = $i + 1;
            // Skip whitespace or comments if needed
            while (isset($tokens[$j]) && is_array($tokens[$j]) && ($tokens[$j][0] === T_WHITESPACE || $tokens[$j][0] === T_COMMENT)) {
                $j++;
            }
            // Now $tokens[$j] should be T_STRING for the function name
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $currentFunc = $tokens[$j][1];
                $inFunction  = true;
                $functionArgs = [];
            }

            // Parse function parameters until we reach the opening brace
            $k = $j + 1;
            while (isset($tokens[$k]) && $tokens[$k] !== '{') {
                // If we detect a variable, note its type if specified
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE) {
                    $argName  = $tokens[$k][1];
                    $argType  = 'mixed'; // default if not found
                    // Look back to see if there's a T_STRING type hint
                    // e.g. function foo(int $bar)
                    if (isset($tokens[$k - 2]) && is_array($tokens[$k - 2]) && $tokens[$k - 2][0] === T_STRING) {
                        $argType = $tokens[$k - 2][1];
                    }
                    $functionArgs[$argName] = $argType;
                }
                $k++;
                if (!isset($tokens[$k])) {
                    break;
                }
            }
            // Store the function signature
            $functions[$currentFunc] = $functionArgs;
        }

        // Detect variable assignment to int or string inside a function
        // $var = 123 or $var = 'abc'
        if ($type === T_VARIABLE && $inFunction) {
            // We should ensure that the next token is '=' as a string, not as an array
            if (isset($tokens[$i + 1]) && !is_array($tokens[$i + 1]) && $tokens[$i + 1] === '=') {
                $varName    = $value;
                $valueToken = $tokens[$i + 2] ?? null;
                if (is_array($valueToken)) {
                    if ($valueToken[0] === T_LNUMBER) {
                        $assignments[$varName] = 'int';
                    } elseif ($valueToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $assignments[$varName] = 'string';
                    } else {
                        // Everything else is 'mixed'
                        $assignments[$varName] = 'mixed';
                    }
                }
            }
        }

        // Detect function calls: T_STRING followed by '('
        if ($type === T_STRING && isset($functions[$value])) {
            // $value is function name
            $funcName = $value;
            // Check if next token is '(' (function call)
            if (isset($tokens[$i + 1]) && $tokens[$i + 1] === '(') {
                // Gather variable arguments until we reach ')'
                $callArgs = [];
                $m = $i + 2;
                while (isset($tokens[$m]) && $tokens[$m] !== ')') {
                    if (is_array($tokens[$m]) && $tokens[$m][0] === T_VARIABLE) {
                        // Found a variable passed as argument
                        $callArgs[] = $tokens[$m][1];
                    }
                    $m++;
                }

                // Compare call argument types with expected function arg types
                $expectedArgs = array_values($functions[$funcName]);

                // Check number of arguments (simple improvement)
                if (count($callArgs) < count($expectedArgs)) {
                    $errors[] = sprintf(
                        "Function `%s` expects at least %d argument(s), but got %d.",
                        $funcName,
                        count($expectedArgs),
                        count($callArgs)
                    );
                } elseif (count($callArgs) > count($expectedArgs)) {
                    $errors[] = sprintf(
                        "Function `%s` expects at most %d argument(s), but got %d.",
                        $funcName,
                        count($expectedArgs),
                        count($callArgs)
                    );
                }

                // Check type mismatches for arguments that do align
                foreach ($callArgs as $k => $arg) {
                    if (isset($expectedArgs[$k])) {
                        $expectedType = $expectedArgs[$k] ?? 'mixed';
                        $actualType   = $assignments[$arg] ?? 'mixed';

                        // If both are known and mismatch != 'mixed' => 'mixed'
                        if ($expectedType !== 'mixed' && $actualType !== 'mixed' && $expectedType !== $actualType) {
                            $errors[] = sprintf(
                                "Type mismatch in call to `%s`: expected %s but got %s for argument %s.",
                                $funcName,
                                $expectedType,
                                $actualType,
                                $arg
                            );
                        }
                    }
                }
            }
        }
    }

    // Close out function context
    if ($token === '}') {
        $inFunction = false;
        $currentFunc = null;
        $functionArgs = [];
    }
}

// Output summary
echo "Function Signatures:\n";
print_r($functions);

echo "\nVariable Assignments (Inferred Types):\n";
print_r($assignments);

echo "\nType Mismatches:\n";
if (empty($errors)) {
    echo "No mismatches found.\n";
} else {
    foreach ($errors as $err) {
        echo "❌ $err\n";
    }
}
