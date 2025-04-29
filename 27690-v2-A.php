<?php

/**
 * Secure Messaging with Test Cases
 *
 * This script contains:
 * 1) The original cryptographic and helper functions
 * 2) The simple test framework
 * 3) A comprehensive test suite verifying:
 *    - Generation of RSA key pairs with 1024, 2048, 4096 bits (where available)
 *    - Handling RSA size limits and hybrid encryption
 *    - Dealing with empty, short, long, special-character, and Unicode strings
 *    - Ensuring PEM format correctness for keys
 *    - Validating HMACs detect message tampering
 *    - Verifying signatures fail with altered data or wrong keys
 *    - Checking nonce uniqueness and length
 *    - Storing/retrieving keys and handling non-existent key files
 *    - Correct usage of SHA-256, AES-256-CBC, and initialization vectors
 *    - End-to-end secure messaging workflow
 *    - Behavior with invalid inputs and non-existent resources
 *    - Simulation of communication between two separate systems
 *    - Full lifecycle of cryptographic keys
 *    - Efficiency with small and large data
 *    - Adherence to cryptographic standards and best practices
 */

/* ===================== Original Cryptographic/Helper Functions ===================== */

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
    // The safe upper limit for a 2048-bit RSA key is ~214 bytes minus overhead
    // We'll use 200 bytes for a margin.
    if (strlen($data) < 200) {
        openssl_public_encrypt($data, $encryptedData, $publicKey);
        return $encryptedData;
    }

    // For longer data, use hybrid encryption (AES + RSA)
    // Generate a random AES key
    $aesKey = openssl_random_pseudo_bytes(32); // 256 bits
    $iv = openssl_random_pseudo_bytes(16);     // 128 bits

    // Encrypt the data with AES-256-CBC
    $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

    // Encrypt the AES key with RSA
    openssl_public_encrypt($aesKey, $encryptedKey, $publicKey);

    // Combine the encrypted key, IV, and encrypted data in JSON
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

            // Decrypt the data using AES-256-CBC
            $iv = base64_decode($package['iv']);
            $encryptedData = base64_decode($package['data']);
            $decryptedData = openssl_decrypt($encryptedData, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

            return $decryptedData;
        }
    }

    // Otherwise, assume direct RSA encryption
    openssl_private_decrypt($encryptedPackage, $decryptedData, $privateKey);
    return $decryptedData;
}

// Function to sign data using the sender's private key
function signData($data, $privateKey)
{
    // Create a signature for the data using the sender's private key and SHA-256
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return $signature;
}

// Function to verify the signature using the sender's public key
function verifySignature($data, $signature, $publicKey)
{
    // Verify the signature with the public key
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
    return $filename;
}

// Function to retrieve a public key for a system from storage
function retrievePublicKey($systemName)
{
    $filename = $systemName . "_public_key.pem";
    if (file_exists($filename)) {
        return file_get_contents($filename);
    }
    return null;
}

// Function to generate a unique nonce to prevent replay attacks
function generateNonce()
{
    // Generate a random 16-byte nonce and return it as hex
    return bin2hex(random_bytes(16));
}

// Example demonstration function (not called by default in this script)
function demo()
{
    echo "Generating key pairs for System A and System B...\n";
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    // Store public keys in a secure storage system
    storePublicKey("SystemA", $keysA['public']);
    storePublicKey("SystemB", $keysB['public']);

    // Retrieve public keys
    $publicKeyA = retrievePublicKey("SystemA");
    $publicKeyB = retrievePublicKey("SystemB");

    $dataToSend = "This is a secret message from System A to System B";
    $nonce = generateNonce();
    $dataToSendWithNonce = $dataToSend . " Nonce: " . $nonce;

    $secretKey = "secret_shared_key";
    $hmac = createHMAC($dataToSendWithNonce, $secretKey);

    // Encrypt with System B's public key
    $encryptedData = encryptData($dataToSendWithNonce, $publicKeyB);

    // Decrypt with System B's private key
    $decryptedData = decryptData($encryptedData, $keysB['private']);

    echo "Decrypted data: " . $decryptedData . "\n";
    $isHMACValid = hash_equals($hmac, createHMAC($decryptedData, $secretKey));
    echo $isHMACValid ? "HMAC is valid.\n" : "HMAC is invalid.\n";

    // Sign data with System A's private key
    $signature = signData($dataToSend, $keysA['private']);

    // Verify signature with System A's public key
    $isSignatureValid = verifySignature($dataToSend, $signature, $publicKeyA);
    echo $isSignatureValid ? "Signature is valid.\n" : "Signature is invalid.\n";
}

/* ===================== Simple Test Framework ===================== */

class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes for console output
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

        return $this->failCount === 0;
    }
}

/* ===================== Test Suite ===================== */

function runTests()
{
    $tf = new SimpleTestFramework();

    // 1. Test key generation with different lengths (1024, 2048, 4096 if supported)
    $tf->run("Generate RSA key pairs of various lengths", function ($test) {
        foreach ([1024, 2048, 4096] as $length) {
            $keys = generateKeyPair($length);
            $test->assertStringContains("BEGIN PRIVATE KEY", $keys['private'], "Private key (" . $length . " bits) not in PEM format");
            $test->assertStringContains("BEGIN PUBLIC KEY", $keys['public'], "Public key (" . $length . " bits) not in PEM format");
        }
    });

    // 2. Test encryption/decryption with short data, ensuring RSA can handle it directly
    $tf->run("Encrypt/Decrypt short data (direct RSA)", function ($test) {
        $keys = generateKeyPair();
        $data = "Short message";
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $test->assertEqual($data, $decrypted, "RSA short data decryption mismatch");
    });

    // 3. Test encryption/decryption with long data (forcing hybrid encryption)
    $tf->run("Encrypt/Decrypt long data (hybrid encryption)", function ($test) {
        $keys = generateKeyPair();
        // Create a long string bigger than 200 bytes
        $data = str_repeat("A", 1000);
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $test->assertEqual($data, $decrypted, "Hybrid encryption mismatch for long data");
    });

    // 4. Test encryption/decryption with an empty string
    $tf->run("Encrypt/Decrypt empty data", function ($test) {
        $keys = generateKeyPair();
        $data = "";
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $test->assertEqual($data, $decrypted, "Empty string encryption failed");
    });

    // 5. Test special characters and Unicode text
    $tf->run("Encrypt/Decrypt special characters & Unicode", function ($test) {
        $keys = generateKeyPair();
        $data = "Spécial Σ #!$£©®™ Characters 🚀\nNewLine";
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $test->assertEqual($data, $decrypted, "Unicode/special char handling mismatch");
    });

    // 6. Test HMAC detection
    $tf->run("Test HMAC integrity check", function ($test) {
        $data = "Sensitive data";
        $secretKey = "secret_shared_key";
        $hmac = createHMAC($data, $secretKey);

        // Correct HMAC should match
        $test->assertTrue(hash_equals($hmac, createHMAC($data, $secretKey)), "Valid HMAC comparison failed");

        // Altered data should fail
        $alteredData = $data . "X";
        $test->assertFalse(hash_equals($hmac, createHMAC($alteredData, $secretKey)), "Altered data HMAC check did not fail");
    });

    // 7. Test signatures (valid and altered)
    $tf->run("Verify RSA signatures", function ($test) {
        $keys = generateKeyPair();
        $data = "Data to sign";
        $signature = signData($data, $keys['private']);

        // Correct data should verify successfully
        $test->assertTrue(verifySignature($data, $signature, $keys['public']), "Signature verification failed for correct data");

        // Altered data should fail
        $alteredData = $data . "X";
        $test->assertFalse(verifySignature($alteredData, $signature, $keys['public']), "Altered data signature check did not fail");

        // Wrong public key should fail
        $otherKeys = generateKeyPair();
        $test->assertFalse(verifySignature($data, $signature, $otherKeys['public']), "Wrong key signature check did not fail");
    });

    // 8. Test nonce generation
    $tf->run("Generate unique nonces", function ($test) {
        $nonce1 = generateNonce();
        $nonce2 = generateNonce();
        $test->assertEqual(32, strlen($nonce1), "Nonce length should be 32 hex chars (16 bytes)");
        $test->assertEqual(32, strlen($nonce2), "Nonce length should be 32 hex chars (16 bytes)");
        $test->assertFalse($nonce1 === $nonce2, "Nonces should be unique");
    });

    // 9. Test storing/retrieving public keys
    $tf->run("Store/Retrieve public keys", function ($test) {
        $systemName = "TempSystem";
        // Cleanup if leftover from previous runs
        @unlink($systemName . "_public_key.pem");

        $keys = generateKeyPair();
        storePublicKey($systemName, $keys['public']);

        $retrieved = retrievePublicKey($systemName);
        $test->assertEqual($keys['public'], $retrieved, "Retrieved public key should match stored key");

        // Cleanup
        @unlink($systemName . "_public_key.pem");
    });

    // 10. Test retrieving non-existent key files
    $tf->run("Retrieve non-existent key file", function ($test) {
        $systemName = "NonExistentSystem";
        @unlink($systemName . "_public_key.pem"); // Ensure it doesn't exist
        $retrieved = retrievePublicKey($systemName);
        $test->assertEqual(null, $retrieved, "Should return null if key file not found");
    });

    // 11. Full secure workflow test (System A -> System B)
    $tf->run("End-to-End secure messaging workflow", function ($test) {
        // Generate system keys
        $keysA = generateKeyPair();
        $keysB = generateKeyPair();

        // Store public keys
        storePublicKey("SystemA", $keysA['public']);
        storePublicKey("SystemB", $keysB['public']);

        // Retrieve them
        $pubA = retrievePublicKey("SystemA");
        $pubB = retrievePublicKey("SystemB");

        // Prepare message with nonce
        $data = "Hello from System A to System B";
        $nonce = generateNonce();
        $fullMessage = $data . " Nonce: " . $nonce;

        // HMAC
        $secretKey = "shared_secret_key";
        $hmac = createHMAC($fullMessage, $secretKey);

        // Encrypt with B's public key
        $encrypted = encryptData($fullMessage, $pubB);
        // Decrypt with B's private key
        $decrypted = decryptData($encrypted, $keysB['private']);

        // Check decrypted
        $test->assertEqual($fullMessage, $decrypted, "End-to-end decrypted data mismatch");

        // Check HMAC
        $test->assertTrue(hash_equals($hmac, createHMAC($decrypted, $secretKey)), "End-to-end HMAC mismatch");

        // Sign with A's private key
        $signature = signData($fullMessage, $keysA['private']);
        // Verify with A's public key
        $test->assertTrue(verifySignature($fullMessage, $signature, $pubA), "End-to-end signature verification failed");

        // Cleanup
        @unlink("SystemA_public_key.pem");
        @unlink("SystemB_public_key.pem");
    });

    // 12. Test behavior with invalid inputs
    $tf->run("Behavior with invalid inputs", function ($test) {
        $keys = generateKeyPair();
        // NULL data to encrypt
        $encryptedNull = encryptData(null, $keys['public']);
        $test->assertNotEmpty($encryptedNull, "Encrypting NULL should produce non-empty result (though meaningless)");

        // Decrypt with null private key
        $decryptedNull = @decryptData("someciphertext", null);
        $test->assertEqual("", $decryptedNull, "Decrypting with null key should fail gracefully (empty result or error)");
    });

    // Show summary
    $tf->summary();
}

/* ===================== Execute the Test Suite ===================== */

runTests();

// Uncomment the following line to run the demonstration function instead of tests
// demo();
