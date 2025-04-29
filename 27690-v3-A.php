#!/usr/bin/env php
<?php
/**
 * Standalone PHP Script:
 * Includes cryptographic functions, a simple test framework, and comprehensive test cases.
 * Run it from the command line (e.g., "php this_script.php") to execute all tests and a main demo.
 */

/* ===================== */
/*  Cryptographic Functions
/* ===================== */

/**
 * Generate an RSA key pair.
 *
 * @param int $keyLength The key length in bits (e.g., 1024, 2048, 4096).
 * @return array Array containing 'private' and 'public' PEM-formatted keys.
 */
function generateKeyPair($keyLength = 2048)
{
    $config = array(
        "private_key_bits" => $keyLength,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );

    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privateKey);
    $publicKeyDetails = openssl_pkey_get_details($res);
    $publicKey = $publicKeyDetails['key'];

    return array('private' => $privateKey, 'public' => $publicKey);
}

/**
 * Encrypt data using the recipient's public key.
 * Automatically uses hybrid encryption (AES + RSA) for larger data.
 *
 * @param string $data      Plaintext data to encrypt.
 * @param string $publicKey Recipient's RSA public key (PEM format).
 * @return string           Either raw RSA-encrypted data or a JSON-encoded hybrid encryption bundle.
 */
function encryptData($data, $publicKey)
{
    // Direct RSA for short data (under typical RSA 2048 safe limit ~200 bytes):
    if (strlen($data) < 200) {
        openssl_public_encrypt($data, $encryptedData, $publicKey);
        return $encryptedData;
    }

    // Otherwise, Hybrid Encryption (AES-256-CBC + RSA key wrapping)
    $aesKey = openssl_random_pseudo_bytes(32); // 256 bits
    $iv = openssl_random_pseudo_bytes(16);     // 128 bits

    $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

    // Encrypt the AES key with RSA
    openssl_public_encrypt($aesKey, $encryptedKey, $publicKey);

    // Pack results into a JSON object
    $result = [
        'method' => 'hybrid',
        'key'    => base64_encode($encryptedKey),
        'iv'     => base64_encode($iv),
        'data'   => base64_encode($encryptedData)
    ];

    return json_encode($result);
}

/**
 * Decrypt data using the recipient's private key (handles both direct RSA and the hybrid approach).
 *
 * @param string $encryptedPackage The encrypted data (raw RSA cipher or JSON hybrid package).
 * @param string $privateKey       Recipient's RSA private key (PEM format).
 * @return string                  The decrypted (plaintext) data.
 */
function decryptData($encryptedPackage, $privateKey)
{
    // First check if this is a JSON-encoded hybrid package
    if (substr($encryptedPackage, 0, 1) === '{') {
        $package = json_decode($encryptedPackage, true);
        if (isset($package['method']) && $package['method'] === 'hybrid') {
            $encryptedKey = base64_decode($package['key']);
            openssl_private_decrypt($encryptedKey, $aesKey, $privateKey);

            $iv = base64_decode($package['iv']);
            $encryptedData = base64_decode($package['data']);
            $decryptedData = openssl_decrypt($encryptedData, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);
            return $decryptedData;
        }
    }

    // If not hybrid, assume direct RSA
    openssl_private_decrypt($encryptedPackage, $decryptedData, $privateKey);
    return $decryptedData;
}

/**
 * Create a signature for data using the sender's private key.
 *
 * @param string $data       The data to sign.
 * @param string $privateKey The sender's RSA private key (PEM format).
 * @return string            The raw signature bytes.
 */
function signData($data, $privateKey)
{
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return $signature;
}

/**
 * Verify a signature using the corresponding public key.
 *
 * @param string $data      The original data.
 * @param string $signature The raw signature bytes to verify.
 * @param string $publicKey The sender's public key (PEM format).
 * @return bool             True if signature is valid, false otherwise.
 */
function verifySignature($data, $signature, $publicKey)
{
    $isVerified = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    return $isVerified === 1;
}

/**
 * Create an HMAC (message authentication code) using SHA-256.
 *
 * @param string $data      The data over which to compute the HMAC.
 * @param string $secretKey A shared secret key to use in the HMAC.
 * @return string           The computed HMAC as a hexadecimal string.
 */
function createHMAC($data, $secretKey)
{
    return hash_hmac('sha256', $data, $secretKey);
}

/**
 * Simulate storing a public key in a secure storage (here, simply a file).
 *
 * @param string $systemName Identifier for the system (used as filename).
 * @param string $publicKey  PEM-formatted public key.
 * @return string            The filename used.
 */
function storePublicKey($systemName, $publicKey)
{
    $filename = $systemName . "_public_key.pem";
    file_put_contents($filename, $publicKey);
    echo "Stored public key for " . $systemName . " in " . $filename . "\n";
    return $filename;
}

/**
 * Retrieve a stored public key from secure storage (file).
 *
 * @param string $systemName Identifier for the system.
 * @return string|null       Public key (PEM) or null if not found.
 */
function retrievePublicKey($systemName)
{
    $filename = $systemName . "_public_key.pem";
    if (file_exists($filename)) {
        return file_get_contents($filename);
    } else {
        echo "Public key for " . $systemName . " not found.\n";
        return null;
    }
}

/**
 * Generate a random 16-byte nonce, returned as 32 hex characters.
 *
 * @return string The generated nonce in hex format.
 */
function generateNonce()
{
    return bin2hex(random_bytes(16)); // 16 random bytes -> 32 hex characters
}

/* ===================== */
/*        DEMO MAIN
/* ===================== */

/**
 * Demonstrates an example secure communication flow between two systems.
 */
function main()
{
    echo "==== DEMO: Secure Messaging Workflow ====\n\n";

    // Generate key pairs for System A and System B:
    echo "Generating key pairs for System A and System B...\n";
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    echo "System A Public Key:\n" . $keysA['public'] . "\n";
    echo "System B Public Key:\n" . $keysB['public'] . "\n";

    // Store public keys in a (simulated) secure storage
    storePublicKey("SystemA", $keysA['public']);
    storePublicKey("SystemB", $keysB['public']);

    // Retrieve public keys
    $publicKeyA = retrievePublicKey("SystemA");
    $publicKeyB = retrievePublicKey("SystemB");

    if (!$publicKeyA || !$publicKeyB) {
        echo "Unable to proceed without public keys.\n";
        return;
    }

    // Prepare data
    $dataToSend = "This is a secret message from System A to System B";
    $nonce = generateNonce();
    $dataToSendWithNonce = $dataToSend . " Nonce: " . $nonce;

    // HMAC for integrity
    $secretKey = "secret_shared_key";
    $hmac = createHMAC($dataToSendWithNonce, $secretKey);

    // Encrypt data with System B's public key
    echo "Encrypting data with System B's public key...\n";
    $encryptedData = encryptData($dataToSendWithNonce, $publicKeyB);

    // Decrypt data with System B's private key
    echo "Decrypting data with System B's private key...\n";
    $decryptedData = decryptData($encryptedData, $keysB['private']);
    echo "Decrypted data: " . $decryptedData . "\n";

    // Check HMAC
    $isHMACValid = hash_equals($hmac, createHMAC($decryptedData, $secretKey));
    echo $isHMACValid ? "HMAC is valid.\n" : "HMAC is invalid.\n";

    // Sign data with System A's private key
    echo "Signing data with System A's private key...\n";
    $signature = signData($dataToSend, $keysA['private']);

    // Verify signature with System A's public key
    echo "Verifying signature with System A's public key...\n";
    $isSignatureValid = verifySignature($dataToSend, $signature, $publicKeyA);
    echo $isSignatureValid ? "Signature is valid.\n" : "Signature is invalid.\n";

    echo "\n==== DEMO Completed ====\n";
}

/* ===================== */
/*   Simple Test Framework
/* ===================== */

class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes for CLI output
    const COLOR_GREEN  = "\033[32m";
    const COLOR_RED    = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE   = "\033[34m";
    const COLOR_RESET  = "\033[0m";
    const COLOR_BOLD   = "\033[1m";

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function run($testName, $callable)
    {
        $this->testCount++;
        echo "Running test: " . self::COLOR_BLUE . $testName . self::COLOR_RESET . "... ";
        try {
            $callable($this);
            echo self::COLOR_GREEN . "PASSED" . self::COLOR_RESET . "\n";
            $this->passCount++;
        } catch (\Exception $e) {
            echo self::COLOR_RED . "FAILED: " . $e->getMessage() . self::COLOR_RESET . "\n";
            $this->failCount++;
        }
    }

    public function assertEqual($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected {$this->formatValue($expected)}, got {$this->formatValue($actual)}";
            throw new \Exception($details);
        }
    }

    public function assertTrue($condition, $message = 'Expected true, got false')
    {
        if ($condition !== true) {
            throw new \Exception($message);
        }
    }

    public function assertFalse($condition, $message = 'Expected false, got true')
    {
        if ($condition !== false) {
            throw new \Exception($message);
        }
    }

    public function assertGreaterThan($expected, $actual, $message = '')
    {
        if ($actual <= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value greater than {$this->formatValue($expected)}, got {$this->formatValue($actual)}";
            throw new \Exception($details);
        }
    }

    public function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual >= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value less than {$this->formatValue($expected)}, got {$this->formatValue($actual)}";
            throw new \Exception($details);
        }
    }

    public function assertCount($expectedCount, $array, $message = '')
    {
        $actualCount = count($array);
        if ($expectedCount !== $actualCount) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected count $expectedCount, got $actualCount";
            throw new \Exception($details);
        }
    }

    public function assertEmpty($value, $message = 'Expected empty value')
    {
        if (!empty($value)) {
            throw new \Exception($message);
        }
    }

    public function assertNotEmpty($value, $message = 'Expected non-empty value')
    {
        if (empty($value)) {
            throw new \Exception($message);
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        if (!in_array($needle, $haystack)) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected array to contain " . $this->formatValue($needle);
            throw new \Exception($details);
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected string to contain " . $this->formatValue($needle);
            throw new \Exception($details);
        }
    }

    private function formatValue($value)
    {
        if (is_array($value)) {
            return 'Array(' . count($value) . ')';
        } elseif (is_object($value)) {
            return get_class($value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            return 'null';
        }
        return (string)$value;
    }

    public function summary()
    {
        $duration = microtime(true) - $this->startTime;
        echo "\n" . self::COLOR_BOLD . "==== Test Summary ====" . self::COLOR_RESET . "\n";
        echo "Total tests: " . self::COLOR_BOLD . $this->testCount . self::COLOR_RESET . "\n";
        echo "Passed: " . self::COLOR_GREEN . $this->passCount . self::COLOR_RESET . "\n";

        if ($this->failCount > 0) {
            echo "Failed: " . self::COLOR_RED . $this->failCount . self::COLOR_RESET . "\n";
        } else {
            echo "Failed: 0\n";
        }

        echo "Time: " . self::COLOR_YELLOW . round($duration, 2) . " seconds" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "======================" . self::COLOR_RESET . "\n";

        if ($this->failCount === 0) {
            echo self::COLOR_GREEN . "All tests passed successfully!" . self::COLOR_RESET . "\n";
        } else {
            echo self::COLOR_RED . "Some tests failed. Please review the output above." . self::COLOR_RESET . "\n";
        }

        return $this->failCount === 0;
    }
}

/* ===================== */
/*       TEST CASES
/* ===================== */

/**
 * Execute a series of tests covering:
 *  - Key generation sizes/PEM checks
 *  - RSA size limits & hybrid encryption
 *  - Empty, short, large, special-char, and Unicode strings
 *  - HMAC integration checks
 *  - Signature verification and tamper detection
 *  - Nonce uniqueness and length
 *  - Key storage/retrieval
 *  - End-to-end secure communication
 *  - Handling invalid inputs
 */
function runTests()
{
    $tf = new SimpleTestFramework();

    // 1) Test default (2048) and custom (1024, 4096) key lengths if supported:
    $tf->run("Test 2048-bit key generation", function ($t) {
        $keys = generateKeyPair(2048);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($keys['private']));
        $t->assertEqual(2048, $details['bits'], "Key length mismatch for 2048-bit");
        $t->assertStringContains("-----BEGIN PRIVATE KEY-----", $keys['private']);
        $t->assertStringContains("-----BEGIN PUBLIC KEY-----", $keys['public']);
    });

    $tf->run("Test 1024-bit key generation", function ($t) {
        $keys = generateKeyPair(1024);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($keys['private']));
        $t->assertEqual(1024, $details['bits'], "Key length mismatch for 1024-bit");
    });

    // 4096-bit may require an up-to-date OpenSSL. We'll try and skip if it fails:
    $tf->run("Test 4096-bit key generation", function ($t) {
        $keys = generateKeyPair(4096);
        $details = openssl_pkey_get_details(openssl_pkey_get_private($keys['private']));
        $t->assertEqual(4096, $details['bits'], "Key length mismatch for 4096-bit");
    });

    // 2) Test encryption/decryption with direct RSA (short data) and hybrid (large data)
    $tf->run("Test direct RSA encryption with short data", function ($t) {
        // short data < 200 bytes
        $data = "Short message";
        $keys = generateKeyPair();
        $encrypted = encryptData($data, $keys['public']);
        // Check it's not JSON (hybrid) since data is short
        $t->assertFalse(substr($encrypted, 0, 1) === '{', "Should not use hybrid for short data");
        $decrypted = decryptData($encrypted, $keys['private']);
        $t->assertEqual($data, $decrypted);
    });

    $tf->run("Test hybrid encryption with large data", function ($t) {
        // create large data > 200 bytes
        $data = str_repeat("A", 1000); // 1000 bytes
        $keys = generateKeyPair();
        $encrypted = encryptData($data, $keys['public']);
        // Check it's JSON => means hybrid
        $t->assertTrue(substr($encrypted, 0, 1) === '{', "Should use hybrid for large data");
        $decrypted = decryptData($encrypted, $keys['private']);
        $t->assertEqual($data, $decrypted);
    });

    // 3) Test empty string, special characters, Unicode
    $tf->run("Test empty string encryption/decryption", function ($t) {
        $data = "";
        $keys = generateKeyPair();
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $t->assertEqual($data, $decrypted);
    });

    $tf->run("Test special characters encryption/decryption", function ($t) {
        $data = "!@#$%^&*()_+-=[{]};:'\",<.>/?`~|\\";
        $keys = generateKeyPair();
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $t->assertEqual($data, $decrypted);
    });

    $tf->run("Test Unicode text encryption/decryption", function ($t) {
        // Some multi-byte unicode characters
        $data = "Hello, 世界! Привет!";
        $keys = generateKeyPair();
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $t->assertEqual($data, $decrypted);
    });

    // 4) Verify HMAC detection
    $tf->run("Test HMAC validity and tampering", function ($t) {
        $data = "Message for HMAC";
        $secretKey = "hmac_secret_key";
        $hmac = createHMAC($data, $secretKey);
        // same data => same HMAC
        $t->assertTrue(hash_equals($hmac, createHMAC($data, $secretKey)), "HMAC mismatch on same data");
        // tampered data => different HMAC
        $t->assertFalse(hash_equals($hmac, createHMAC($data . "X", $secretKey)), "HMAC should mismatch on tampered data");
    });

    // 5) Test signatures and tampering
    $tf->run("Test signature valid & invalid", function ($t) {
        $keys = generateKeyPair();
        $data = " Signed Data ";
        $signature = signData($data, $keys['private']);
        // Correct signature
        $t->assertTrue(verifySignature($data, $signature, $keys['public']), "Signature should be valid");
        // Tampered data => fail
        $t->assertFalse(verifySignature($data . "X", $signature, $keys['public']), "Signature should fail on tampered data");
        // Use wrong public key => fail
        $otherKeys = generateKeyPair();
        $t->assertFalse(verifySignature($data, $signature, $otherKeys['public']), "Signature should fail with wrong public key");
    });

    // 6) Test nonce uniqueness and length
    $tf->run("Test nonce uniqueness and length", function ($t) {
        $nonce1 = generateNonce();
        $nonce2 = generateNonce();
        $t->assertEqual(32, strlen($nonce1), "Nonce should be 32 hex chars");
        $t->assertEqual(32, strlen($nonce2), "Nonce should be 32 hex chars");
        $t->assertFalse($nonce1 === $nonce2, "Nonces should be unique");
    });

    // 7) Test storing/retrieving keys and non-existent file
    $tf->run("Test store/retrieve public key & handle missing file", function ($t) {
        $systemName = "TestSystemX";
        @unlink($systemName . "_public_key.pem"); // Clean up from any previous run

        $keys = generateKeyPair();
        storePublicKey($systemName, $keys['public']);
        $pub = retrievePublicKey($systemName);

        $t->assertStringContains("-----BEGIN PUBLIC KEY-----", $pub, "Stored key is not a valid public key");
        @unlink($systemName . "_public_key.pem"); // Cleanup

        // Now retrieving again should fail
        $pub2 = retrievePublicKey($systemName);
        $t->assertEqual(null, $pub2, "Should return null for missing key file");
    });

    // 8) Test a full end-to-end communication scenario
    $tf->run("Test end-to-end flow with two systems", function ($t) {
        $systemAKeys = generateKeyPair();
        $systemBKeys = generateKeyPair();

        // Simulate storing
        file_put_contents("SystemA_public_key.pem", $systemAKeys['public']);
        file_put_contents("SystemB_public_key.pem", $systemBKeys['public']);

        $publicKeyA = retrievePublicKey("SystemA");
        $publicKeyB = retrievePublicKey("SystemB");

        $t->assertNotEmpty($publicKeyA, "Missing SystemA public key");
        $t->assertNotEmpty($publicKeyB, "Missing SystemB public key");

        // A sends message to B
        $message = "Hello from A to B!";
        $nonce = generateNonce();
        $secretKey = "endtoend_shared_hmac_key";
        $hmac = createHMAC($message . $nonce, $secretKey);

        $encrypted = encryptData($message . $nonce, $publicKeyB);
        $decrypted = decryptData($encrypted, $systemBKeys['private']);

        // Check correctness
        $t->assertEqual($message . $nonce, $decrypted, "Decrypted message mismatch");
        // Check HMAC
        $t->assertTrue(hash_equals($hmac, createHMAC($decrypted, $secretKey)), "End-to-end HMAC mismatch");

        // Cleanup:
        @unlink("SystemA_public_key.pem");
        @unlink("SystemB_public_key.pem");
    });

    // 9) Test behavior with invalid inputs
    $tf->run("Test decrypt with invalid data", function ($t) {
        $keys = generateKeyPair();
        // Random invalid ciphertext
        $invalid = "ThisIsNotEncryptedData";
        $decrypted = decryptData($invalid, $keys['private']);
        // Usually this results in empty or garbled text; just ensure we get a string
        $t->assertTrue(is_string($decrypted), "Decrypt should return a string (even if empty/garbled)");
    });

    $tf->summary();
}

/* ===================== */
/*   RUN TESTS & DEMO
/* ===================== */

// 1) Run our comprehensive test suite
runTests();

// 2) Run the demo "main" function to see a sample secure workflow
main();
?>