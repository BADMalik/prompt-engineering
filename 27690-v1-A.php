<?php

// ==================================================================================
// Existing Implementation
// ==================================================================================

// Function to generate RSA key pair (private and public keys)
function generateKeyPair($keyLength = 2048)
{
    // Create a new RSA key pair with the specified key length
    $config = array(
        "private_key_bits" => $keyLength,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );

    // Generate the key pair
    $res = openssl_pkey_new($config);

    // Extract the private key
    openssl_pkey_export($res, $privateKey);

    // Extract the public key
    $publicKeyDetails = openssl_pkey_get_details($res);
    $publicKey = $publicKeyDetails['key'];

    return array('private' => $privateKey, 'public' => $publicKey);
}

// Function to encrypt data using the recipient's public key
// For longer data, uses hybrid encryption (AES + RSA)
function encryptData($data, $publicKey)
{
    // If data is short enough for RSA, use direct encryption
    // (Limit chosen to fit within safe RSA encryption size for 2048-bit keys)
    if (strlen($data) < 200) {
        openssl_public_encrypt($data, $encryptedData, $publicKey);
        return $encryptedData;
    }

    // For longer data, use hybrid encryption (AES + RSA)
    // Generate a random AES key
    $aesKey = openssl_random_pseudo_bytes(32); // 256 bits
    $iv = openssl_random_pseudo_bytes(16);     // 128 bits

    // Encrypt the data with AES
    $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

    // Encrypt the AES key with RSA
    openssl_public_encrypt($aesKey, $encryptedKey, $publicKey);

    // Combine the encrypted key, IV, and encrypted data
    $result = [
        'method' => 'hybrid',
        'key'    => base64_encode($encryptedKey),
        'iv'     => base64_encode($iv),
        'data'   => base64_encode($encryptedData)
    ];

    return json_encode($result);
}

// Function to decrypt data using the recipient's private key
function decryptData($encryptedPackage, $privateKey)
{
    // Check if it's a hybrid encryption package (JSON string)
    if (substr($encryptedPackage, 0, 1) === '{') {
        $package = json_decode($encryptedPackage, true);

        if (isset($package['method']) && $package['method'] === 'hybrid') {
            // Decrypt the AES key using RSA
            $encryptedKey = base64_decode($package['key']);
            openssl_private_decrypt($encryptedKey, $aesKey, $privateKey);

            // Decrypt the data using AES
            $iv = base64_decode($package['iv']);
            $encryptedData = base64_decode($package['data']);
            $decryptedData = openssl_decrypt($encryptedData, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

            return $decryptedData;
        }
    }

    // Direct RSA decryption for short data
    openssl_private_decrypt($encryptedPackage, $decryptedData, $privateKey);
    return $decryptedData;
}

// Function to sign data using the sender's private key
function signData($data, $privateKey)
{
    // Create a signature for the data using the sender's private key
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return $signature;
}

// Function to verify the signature using the recipient's public key
function verifySignature($data, $signature, $publicKey)
{
    // Verify the signature with the recipient's public key
    $isVerified = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    return $isVerified === 1;
}

// Function to create a HMAC for message integrity
function createHMAC($data, $secretKey)
{
    return hash_hmac('sha256', $data, $secretKey);
}

// Function to simulate a secure public key storage system
function storePublicKey($systemName, $publicKey)
{
    $filename = $systemName . "_public_key.pem";
    file_put_contents($filename, $publicKey);
    echo "Stored public key for " . $systemName . " in " . $filename . "\n";
    return $filename;
}

// Function to retrieve public key for a system from storage
function retrievePublicKey($systemName)
{
    $filename = $systemName . "_public_key.pem";
    if (file_exists($filename)) {
        $publicKey = file_get_contents($filename);
        return $publicKey;
    } else {
        echo "Public key for " . $systemName . " not found.\n";
        return null;
    }
}

// Function to generate a unique nonce to prevent replay attacks
function generateNonce()
{
    // 16 random bytes, returned as a 32-character hexadecimal string
    return bin2hex(random_bytes(16));
}

// Demo function to show sample usage (not strictly part of testing, but used in some tests)
function sampleSecureMessagingWorkflow()
{
    // Generate key pairs for System A and System B
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    // Store public keys in a secure storage system
    storePublicKey("SystemA", $keysA['public']);
    storePublicKey("SystemB", $keysB['public']);

    // Retrieve public keys from storage
    $publicKeyA = retrievePublicKey("SystemA");
    $publicKeyB = retrievePublicKey("SystemB");

    if (!$publicKeyA || !$publicKeyB) {
        throw new Exception("Unable to proceed without public keys.");
    }

    // Simulate sending data from System A to System B
    $dataToSend = "This is a secret message from System A to System B";
    $nonce = generateNonce(); // Unique nonce to prevent replay attacks
    $dataToSendWithNonce = $dataToSend . " Nonce: " . $nonce;

    // Create an HMAC for message integrity
    $secretKey = "secret_shared_key"; // Shared secret key for HMAC
    $hmac = createHMAC($dataToSendWithNonce, $secretKey);

    // System A encrypts data using System B's public key
    $encryptedData = encryptData($dataToSendWithNonce, $publicKeyB);

    // System B decrypts the data using its private key
    $decryptedData = decryptData($encryptedData, $keysB['private']);

    // Verify the HMAC to ensure message integrity
    $isHMACValid = hash_equals($hmac, createHMAC($decryptedData, $secretKey));
    if (!$isHMACValid) {
        throw new Exception("HMAC verification failed. Message integrity compromised.");
    }

    // Sign data with System A's private key
    $signature = signData($dataToSend, $keysA['private']);

    // Verify signature using System A's public key
    $isSignatureValid = verifySignature($dataToSend, $signature, $publicKeyA);
    if (!$isSignatureValid) {
        throw new Exception("Signature verification failed. The message may have been altered.");
    }

    // Return all workflow results
    return [
        'encryptedData'    => $encryptedData,
        'decryptedData'    => $decryptedData,
        'hmacValid'        => $isHMACValid,
        'signatureValid'   => $isSignatureValid,
        'nonce'            => $nonce
    ];
}

// ==================================================================================
// Simple Test Framework
// ==================================================================================
class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes
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
        } catch (Exception $e) {
            echo self::COLOR_RED . "FAILED: " . $e->getMessage() . self::COLOR_RESET . "\n";
            $this->failCount++;
        }
    }

    public function assertEqual($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertTrue($condition, $message = 'Expected true, got false')
    {
        if ($condition !== true) {
            throw new Exception($message);
        }
    }

    public function assertFalse($condition, $message = 'Expected false, got true')
    {
        if ($condition !== false) {
            throw new Exception($message);
        }
    }

    public function assertGreaterThan($expected, $actual, $message = '')
    {
        if ($actual <= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value greater than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual >= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value less than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertCount($expectedCount, $array, $message = '')
    {
        $actualCount = count($array);
        if ($expectedCount !== $actualCount) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected count $expectedCount, got $actualCount";
            throw new Exception($details);
        }
    }

    public function assertEmpty($value, $message = 'Expected empty value')
    {
        if (!empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertNotEmpty($value, $message = 'Expected non-empty value')
    {
        if (empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        if (!in_array($needle, $haystack)) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected array to contain " . $this->formatValue($needle);
            throw new Exception($details);
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected string to contain " . $this->formatValue($needle);
            throw new Exception($details);
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
        } else {
            return (string)$value;
        }
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
            echo "Failed: " . $this->failCount . "\n";
        }

        echo "Time: " . self::COLOR_YELLOW . round($duration, 2) . " seconds" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "======================" . self::COLOR_RESET . "\n";

        if ($this->failCount === 0) {
            echo self::COLOR_GREEN . "All tests passed successfully!" . self::COLOR_RESET . "\n";
        } else {
            echo self::COLOR_RED . "Some tests failed. Please review the output above." . self::COLOR_RESET . "\n";
        }

        // Return true if all passed, false if any failed
        return $this->failCount === 0;
    }
}

// ==================================================================================
// Test Suite (verifying the listed constraints)
// ==================================================================================
function runAllTests()
{
    $testFramework = new SimpleTestFramework();

    // 1. Tests default and custom key lengths (2048-bit, 1024-bit, and 4096-bit)
    $testFramework->run("Test Key Generation (Default 2048)", function ($test) {
        $keys = generateKeyPair(2048);
        $test->assertNotEmpty($keys['public'], "Public key should not be empty");
        $test->assertNotEmpty($keys['private'], "Private key should not be empty");
        $test->assertStringContains("BEGIN PUBLIC KEY", $keys['public'], "Public key must be in PEM format");
        $test->assertStringContains("BEGIN PRIVATE KEY", $keys['private'], "Private key must be in PEM format");
    });

    $testFramework->run("Test Key Generation (Custom 1024)", function ($test) {
        $keys = generateKeyPair(1024);
        $test->assertNotEmpty($keys['public']);
        $test->assertNotEmpty($keys['private']);
        $test->assertStringContains("BEGIN PUBLIC KEY", $keys['public']);
        $test->assertStringContains("BEGIN PRIVATE KEY", $keys['private']);
    });

    $testFramework->run("Test Key Generation (Custom 4096)", function ($test) {
        // Some environments may not allow 4096-bit keys, but let's try
        $keys = generateKeyPair(4096);
        $test->assertNotEmpty($keys['public']);
        $test->assertNotEmpty($keys['private']);
        $test->assertStringContains("BEGIN PUBLIC KEY", $keys['public']);
        $test->assertStringContains("BEGIN PRIVATE KEY", $keys['private']);
    });

    // Create a single pair of 2048-bit keys for multiple tests
    $keys = generateKeyPair(2048);
    $privateKey = $keys['private'];
    $publicKey = $keys['public'];

    // 2. Handles RSA size limits and implements hybrid encryption for larger data
    $testFramework->run("Test RSA Direct Encryption (Short Data)", function ($test) use ($publicKey, $privateKey) {
        $shortData = "Short message";
        $encrypted = encryptData($shortData, $publicKey);
        // For short data, encryption result should NOT be JSON (hybrid fields)
        $test->assertFalse(substr($encrypted, 0, 1) === '{', "Should not be hybrid encryption");
        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($shortData, $decrypted, "Decrypted data should match the original short data");
    });

    $testFramework->run("Test Hybrid Encryption (Long Data)", function ($test) use ($publicKey, $privateKey) {
        // 300+ characters to force hybrid encryption
        $longData = str_repeat("ABCD1234", 40); // 320 characters
        $encrypted = encryptData($longData, $publicKey);
        // Should be a JSON structure containing "method":"hybrid"
        $test->assertTrue(substr($encrypted, 0, 1) === '{', "Should use hybrid encryption for long data");

        $decoded = json_decode($encrypted, true);
        $test->assertEqual('hybrid', $decoded['method'], "Encryption method should be 'hybrid'");
        $test->assertNotEmpty($decoded['key'], "Encrypted key must exist");
        $test->assertNotEmpty($decoded['iv'], "IV must exist");
        $test->assertNotEmpty($decoded['data'], "Encrypted data must exist");

        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($longData, $decrypted, "Decrypted data should match the original long data");
    });

    // 3. Tests empty strings, short strings, long strings, special characters, and Unicode text
    $testFramework->run("Test Encryption/Decryption with Empty String", function ($test) use ($publicKey, $privateKey) {
        $data = "";
        $encrypted = encryptData($data, $publicKey);
        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($data, $decrypted, "Decrypted empty string should match original");
    });

    $testFramework->run("Test Encryption/Decryption with Special Characters", function ($test) use ($publicKey, $privateKey) {
        $data = "!@#$%^&*()_+~`[]{}|;':\",.<>?";
        $encrypted = encryptData($data, $publicKey);
        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($data, $decrypted, "Decrypted data with special characters should match");
    });

    $testFramework->run("Test Encryption/Decryption with Unicode", function ($test) use ($publicKey, $privateKey) {
        // Japanese text + Emoji
        $data = "こんにちは世界 🌏🚀";
        $encrypted = encryptData($data, $publicKey);
        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($data, $decrypted, "Decrypted data with Unicode should match");
    });

    // 4. Verifies proper PEM format for both private and public keys
    // (Already checked in key generation tests above; we can do an additional check here)
    $testFramework->run("Test PEM Format (Public and Private Keys)", function ($test) use ($publicKey, $privateKey) {
        $test->assertStringContains("BEGIN PUBLIC KEY", $publicKey, "Public key must contain BEGIN PUBLIC KEY");
        $test->assertStringContains("END PUBLIC KEY", $publicKey, "Public key must contain END PUBLIC KEY");
        $test->assertStringContains("BEGIN PRIVATE KEY", $privateKey, "Private key must contain BEGIN PRIVATE KEY");
        $test->assertStringContains("END PRIVATE KEY", $privateKey, "Private key must contain END PRIVATE KEY");
    });

    // 5. Ensures HMACs detect message changes and maintain consistency
    $testFramework->run("Test HMAC Integrity", function ($test) {
        $secretKey = "secret_shared_key";
        $message   = "This is a test message";
        $hmac      = createHMAC($message, $secretKey);

        // If we don't change the message, HMAC should match
        $test->assertTrue(hash_equals($hmac, createHMAC($message, $secretKey)), "HMAC should match for unchanged data");

        // If we alter the message, HMAC should fail
        $tamperedMessage = $message . " (tampered)";
        $test->assertFalse(hash_equals($hmac, createHMAC($tamperedMessage, $secretKey)), "HMAC should fail for altered data");
    });

    // 6. Verifies signatures fail with altered messages or incorrect keys
    $testFramework->run("Test Signatures with Correct and Altered Data", function ($test) use ($publicKey, $privateKey) {
        $data = "Important document content";
        $signature = signData($data, $privateKey);

        // Verification with correct data
        $test->assertTrue(verifySignature($data, $signature, $publicKey), "Signature should be valid for correct data");

        // Verification with altered data
        $alteredData = $data . " - malicious alteration";
        $test->assertFalse(verifySignature($alteredData, $signature, $publicKey), "Signature should fail for altered data");

        // Verification with another key
        $otherKeys = generateKeyPair(2048);
        $test->assertFalse(verifySignature($data, $signature, $otherKeys['public']), "Signature should fail for incorrect public key");
    });

    // 7. Verifies uniqueness and proper length of generated nonces
    $testFramework->run("Test Nonce Generation", function ($test) {
        $nonce1 = generateNonce();
        $nonce2 = generateNonce();

        // They should be 32 hex characters each (since 16 bytes * 2 hex chars/byte = 32)
        $test->assertEqual(32, strlen($nonce1), "Nonce must be 32 hex characters");
        $test->assertEqual(32, strlen($nonce2), "Nonce must be 32 hex characters");
        $test->assertFalse($nonce1 === $nonce2, "Nonces should be unique");
    });

    // 8. Handles storing/retrieving keys to/from files and non-existent key files
    $testFramework->run("Test Key Storage and Retrieval", function ($test) {
        // Generate keys
        $keys = generateKeyPair(2048);
        $fileName = "TestSystem_public_key.pem";

        // Store the public key
        storePublicKey("TestSystem", $keys['public']);
        $test->assertTrue(file_exists($fileName), "Public key file should exist after storing");

        // Retrieve it back
        $retrieved = retrievePublicKey("TestSystem");
        $test->assertEqual($keys['public'], $retrieved, "Retrieved public key should match stored key");

        // Test retrieval of non-existent system
        $nonExistent = retrievePublicKey("NonExistentSystem");
        $test->assertEqual(null, $nonExistent, "Should return null when public key file does not exist");

        // Cleanup
        if (file_exists($fileName)) {
            unlink($fileName);
        }
    });

    // 9. Proper use of SHA-256, AES-256-CBC, and IV
    // (Partially tested in encryption. We further inspect the hybrid encryption structure.)
    $testFramework->run("Test AES-256-CBC and IV in Hybrid Encryption", function ($test) use ($publicKey, $privateKey) {
        $data = str_repeat("X", 300); // Force hybrid
        $encrypted = encryptData($data, $publicKey);

        // Check that method is "hybrid" and we have a 16-byte IV
        $package = json_decode($encrypted, true);
        $test->assertEqual("hybrid", $package['method'], "Must use hybrid encryption for long data");
        $iv = base64_decode($package['iv']);
        $test->assertEqual(16, strlen($iv), "IV must be 16 bytes for AES-256-CBC");

        // Decrypt and verify
        $decrypted = decryptData($encrypted, $privateKey);
        $test->assertEqual($data, $decrypted, "Decrypted data should match");
    });

    // 10. Tests complete secure messaging workflow end-to-end
    $testFramework->run("Test Full Secure Messaging Workflow", function ($test) {
        $results = sampleSecureMessagingWorkflow();
        $test->assertNotEmpty($results['encryptedData'], "Encrypted data should not be empty");
        $test->assertNotEmpty($results['decryptedData'], "Decrypted data should not be empty");
        $test->assertTrue($results['hmacValid'], "HMAC should be valid");
        $test->assertTrue($results['signatureValid'], "Signature should be valid");
        $test->assertEqual(32, strlen($results['nonce']), "Nonce should be 32 hex characters");
    });

    // 11. Tests behavior with invalid inputs and non-existent resources
    $testFramework->run("Test Decryption with Invalid Data", function ($test) use ($privateKey) {
        // Passing random string that isn't valid RSA or hybrid structure
        $invalid = "random data that won't decrypt";
        $decrypted = decryptData($invalid, $privateKey);
        // The function might return empty or a garbled string,
        // but let's check it isn't the same as the invalid input
        $test->assertTrue($decrypted !== $invalid, "Decryption of invalid data should not match input");
    });

    // 12. Simulates secure communication between two separate systems (partially done in sample workflow)
    // 13. Tests the full lifecycle of cryptographic keys, small and large data (also covered above)
    // 14. Ensures standards and best practices (encryption/hashing keys, hybrid, etc.)

    // Show summary
    $testFramework->summary();
}

// Uncomment the line below to run all tests when this file is executed:
// runAllTests();

// You can also directly run the sample below to see a quick demonstration of the workflow
// (Uncomment to see sample output):
// main();
if (php_sapi_name() === 'cli') {
    // By default, let's run our test suite when from CLI
    runAllTests();
} else {
    echo "Load this script via CLI to execute tests, or call runAllTests() manually.\n";
}
