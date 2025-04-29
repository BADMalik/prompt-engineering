<?php
// Original Cryptographic Functions
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
    if (strlen($data) < 200) { // Safe limit for 2048-bit RSA
        openssl_public_encrypt($data, $encryptedData, $publicKey);
        return $encryptedData;
    }

    // For longer data, use hybrid encryption (AES + RSA)
    // Generate a random AES key
    $aesKey = openssl_random_pseudo_bytes(32); // 256 bits
    $iv = openssl_random_pseudo_bytes(16); // 128 bits

    // Encrypt the data with AES
    $encryptedData = openssl_encrypt($data, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA, $iv);

    // Encrypt the AES key with RSA
    openssl_public_encrypt($aesKey, $encryptedKey, $publicKey);

    // Combine the encrypted key, IV, and encrypted data
    $result = [
        'method' => 'hybrid',
        'key' => base64_encode($encryptedKey),
        'iv' => base64_encode($iv),
        'data' => base64_encode($encryptedData)
    ];

    return json_encode($result);
}

// Function to decrypt data using the recipient's private key
function decryptData($encryptedPackage, $privateKey)
{
    // Check if it's a hybrid encryption package (JSON string)
    if (substr($encryptedPackage, 0, 1) === '{') {
        $package = json_decode($encryptedPackage, true);

        if ($package['method'] === 'hybrid') {
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
    return bin2hex(random_bytes(16)); // Generate a random 16-byte nonce
}

// Main execution block
function main()
{
    // Generate key pairs for System A and System B
    echo "Generating key pairs for System A and System B...\n";
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    echo "System A Public Key:\n" . $keysA['public'] . "\n";
    echo "System B Public Key:\n" . $keysB['public'] . "\n";

    // Store public keys in a secure storage system
    storePublicKey("SystemA", $keysA['public']);
    storePublicKey("SystemB", $keysB['public']);

    // Retrieve public keys from storage
    $publicKeyA = retrievePublicKey("SystemA");
    $publicKeyB = retrievePublicKey("SystemB");

    if (!$publicKeyA || !$publicKeyB) {
        echo "Unable to proceed without public keys.\n";
        return;
    }

    // Simulate sending data from System A to System B
    $dataToSend = "This is a secret message from System A to System B";
    $nonce = generateNonce(); // Unique nonce to prevent replay attacks
    $dataToSendWithNonce = $dataToSend . " Nonce: " . $nonce;

    // Create an HMAC for message integrity
    $secretKey = "secret_shared_key"; // Shared secret key for HMAC
    $hmac = createHMAC($dataToSendWithNonce, $secretKey);

    // System A encrypts data using System B's public key
    echo "Encrypting data with System B's public key...\n";
    $encryptedData = encryptData($dataToSendWithNonce, $publicKeyB);

    // System B decrypts the data using its private key
    echo "Decrypting data with System B's private key...\n";
    $decryptedData = decryptData($encryptedData, $keysB['private']);
    echo "Decrypted data: " . $decryptedData . "\n";

    // Verify the HMAC to ensure message integrity
    $isHMACValid = hash_equals($hmac, createHMAC($decryptedData, $secretKey));
    echo $isHMACValid ? "HMAC is valid.\n" : "HMAC is invalid.\n";

    // Sign data with System A's private key
    echo "Signing data with System A's private key...\n";
    $signature = signData($dataToSend, $keysA['private']);

    // Verify signature using System A's public key
    echo "Verifying signature with System A's public key...\n";
    $isSignatureValid = verifySignature($dataToSend, $signature, $publicKeyA);
    echo $isSignatureValid ? "Signature is valid.\n" : "Signature is invalid.\n";
}

// Test Framework class
class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes
    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";

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

// Clean up any existing test key files
function cleanupKeyFiles()
{
    $testSystems = ['TestSystemA', 'TestSystemB', 'SystemA', 'SystemB', 'EmptySystem'];
    foreach ($testSystems as $system) {
        $filename = $system . "_public_key.pem";
        if (file_exists($filename)) {
            unlink($filename);
        }
    }
}

// Helper function to verify that encrypt-decrypt process works
function testEncryptDecryptCycle($test, $keysA, $keysB)
{
    $originalData = "Test message for encryption and decryption";

    // A encrypts message for B using B's public key
    $encryptedData = encryptData($originalData, $keysB['public']);
    $test->assertNotEmpty($encryptedData, "Encrypted data should not be empty");

    // B decrypts message using B's private key
    $decryptedData = decryptData($encryptedData, $keysB['private']);
    $test->assertEqual($originalData, $decryptedData, "Decrypted data should match original data");

    return $encryptedData;
}
$testFramework = new SimpleTestFramework();

$testFramework->run('Test default and custom key lengths', function ($test) {
    $keys2048 = generateKeyPair(2048);
    $keys1024 = generateKeyPair(1024);
    $keys4096 = generateKeyPair(4096);

    $test->assertCount(2, $keys2048);
    $test->assertCount(2, $keys1024);
    $test->assertCount(2, $keys4096);
});

$testFramework->run('Test RSA size limits and hybrid encryption', function ($test) {
    $keys = generateKeyPair();
    $shortData = "Short data";
    $longData = str_repeat("A", 256); // Larger than RSA limit

    $encryptedShort = encryptData($shortData, $keys['public']);
    $encryptedLong = encryptData($longData, $keys['public']);

    $test->assertStringContains("method", $encryptedLong);
    $test->assertNotStringContains("method", $encryptedShort);
});

$testFramework->run('Test various data types', function ($test) {
    $keys = generateKeyPair();
    $testData = [
        "", // Empty string
        "Short string",
        str_repeat("A", 1000), // Long string
        "Special characters: !@#$%^&*()_+-=[]{}|;':\",./<>?",
        "Unicode text: こんにちは世界"
    ];

    foreach ($testData as $data) {
        $encrypted = encryptData($data, $keys['public']);
        $decrypted = decryptData($encrypted, $keys['private']);
        $test->assertEqual($data, $decrypted, "Failed to encrypt/decrypt: $data");
    }
});

$testFramework->run('Test PEM format for keys', function ($test) {
    $keys = generateKeyPair();
    $test->assertStringContains("-----BEGIN RSA PRIVATE KEY-----", $keys['private']);
    $test->assertStringContains("-----BEGIN PUBLIC KEY-----", $keys['public']);
});

$testFramework->run('Test HMAC integrity', function ($test) {
    $data = "Test data";
    $secretKey = "secret_shared_key";
    $hmac = createHMAC($data, $secretKey);

    $test->assertTrue(hash_equals($hmac, createHMAC($data, $secretKey)), "HMAC mismatch");
    $test->assertFalse(hash_equals($hmac, createHMAC($data . " modified", $secretKey)), "HMAC should detect changes");
});

$testFramework->run('Test signature verification', function ($test) {
    $keys = generateKeyPair();
    $data = "Test data";
    $signature = signData($data, $keys['private']);

    $test->assertTrue(verifySignature($data, $signature, $keys['public']), "Signature verification failed");
    $test->assertFalse(verifySignature($data . " modified", $signature, $keys['public']), "Signature should fail with altered data");
    $test->assertFalse(verifySignature($data, $signature, generateKeyPair()['public']), "Signature should fail with incorrect key");
});

$testFramework->run('Test nonce generation', function ($test) {
    $nonce1 = generateNonce();
    $nonce2 = generateNonce();

    $test->assertNotEmpty($nonce1);
    $test->assertNotEmpty($nonce2);
    $test->assertNotEqual($nonce1, $nonce2, "Nonces should be unique");
    $test->assertEqual(32, strlen($nonce1), "Nonce should be 32 characters long");
});

$testFramework->run('Test key storage and retrieval', function ($test) {
    $keys = generateKeyPair();
    $systemName = "TestSystem";

    storePublicKey($systemName, $keys['public']);
    $retrievedKey = retrievePublicKey($systemName);

    $test->assertEqual($keys['public'], $retrievedKey, "Retrieved key does not match stored key");

    $nonExistentKey = retrievePublicKey("NonExistentSystem");
    $test->assertNull($nonExistentKey, "Retrieved key for non-existent system should be null");
});

$testFramework->run('Test complete secure messaging workflow', function ($test) {
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    $dataToSend = "This is a secret message";
    $encryptedData = encryptData($dataToSend, $keysB['public']);
    $decryptedData = decryptData($encryptedData, $keysB['private']);

    $test->assertEqual($dataToSend, $decryptedData, "End-to-end secure messaging failed");
});

$testFramework->run('Test invalid inputs and non-existent resources', function ($test) {
    $keys = generateKeyPair();

    $test->assertFalse(encryptData($data, "invalid_public_key"), "Encryption should fail with invalid public key");
    $test->assertFalse(decryptData("invalid_encrypted_data", $keys['private']), "Decryption should fail with invalid data");
});

$testFramework->run('Test secure communication between two systems', function ($test) {
    $keysA = generateKeyPair();
    $keysB = generateKeyPair();

    storePublicKey("SystemA", $keysA['public']);
    storePublicKey("SystemB", $keysB['public']);

    $publicKeyA = retrievePublicKey("SystemA");
    $publicKeyB = retrievePublicKey("SystemB");

    $dataToSend = "Secure message from System A to System B";
    $encryptedData = encryptData($dataToSend, $publicKeyB);
    $decryptedData = decryptData($encryptedData, $keysB['private']);

    $test->assertEqual($dataToSend, $decryptedData, "Secure communication between systems failed");
});

$testFramework->run('Test full lifecycle of cryptographic keys', function ($test) {
    $keys = generateKeyPair();
    $data = "Test data";

    $encryptedData = encryptData($data, $keys['public']);
    $decryptedData = decryptData($encryptedData, $keys['private']);

    $test->assertEqual($data, $decryptedData, "Full lifecycle of cryptographic keys failed");
});

$testFramework->run('Test handling of small and large data sizes', function ($test) {
    $keys = generateKeyPair();
    $smallData = "Small data";
    $largeData = str_repeat("A", 10000); // Large data

    $encryptedSmall = encryptData($smallData, $keys['public']);
    $encryptedLarge = encryptData($largeData, $keys['public']);

    $decryptedSmall = decryptData($encryptedSmall, $keys['private']);
    $decryptedLarge = decryptData($encryptedLarge, $keys['private']);

    $test->assertEqual($smallData, $decryptedSmall, "Encryption/decryption of small data failed");
    $test->assertEqual($largeData, $decryptedLarge, "Encryption/decryption of large data failed");
});

$testFramework->summary();
