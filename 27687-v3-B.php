<?php
// Configuration constants
define('KEY_LENGTH', 32); // 256-bit key length
define('ROTATION_INTERVAL', 86400); // 24 hours in seconds
define('LOG_ROTATION_THRESHOLD', 500); // Number of lines before log rotation
define('STORAGE_DIR', './storage');
define('KEYS_FILE', STORAGE_DIR . '/keys.txt');
define('AUDIT_LOG_FILE', STORAGE_DIR . '/audit.log');

// Ensure storage directory exists
if (!file_exists(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

// Function to generate a new cryptographically secure key
function generateKey()
{
    return bin2hex(random_bytes(KEY_LENGTH));
}

// Function to load existing keys from file
function loadKeys()
{
    if (file_exists(KEYS_FILE)) {
        return file(KEYS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    }
    return [];
}

// Function to save keys to file
function saveKeys($keys)
{
    file_put_contents(KEYS_FILE, implode(PHP_EOL, $keys));
}

// Function to log an event with a timestamp and previous log hash
function logEvent($message, $previousHash = null)
{
    $timestamp = date('c');
    $logEntry = "$timestamp|$message";
    $hash = hash('sha256', $logEntry);

    if ($previousHash !== null) {
        $logEntry .= "|$previousHash";
    }

    file_put_contents(AUDIT_LOG_FILE, $logEntry . PHP_EOL, FILE_APPEND);

    // Rotate log if it exceeds the threshold
    $lines = file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES);
    if (count($lines) > LOG_ROTATION_THRESHOLD) {
        $half = array_slice($lines, -LOG_ROTATION_THRESHOLD / 2);
        file_put_contents(AUDIT_LOG_FILE, implode(PHP_EOL, $half) . PHP_EOL, FILE_APPEND);
    }

    return $hash;
}

// Function to get the last log hash
function getLastLogHash()
{
    $lines = file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES);
    $lastLine = end($lines);
    if ($lastLine) {
        $parts = explode('|', $lastLine);
        return end($parts); // Return the last part which is the hash
    }
    return null;
}

// Function to simulate key access logging
function accessKey($key, $role)
{
    $message = "Access: Key=$key, Role=$role";
    logEvent($message, getLastLogHash());
}

// Function to rotate keys
function rotateKeys(&$keys)
{
    $newKey = generateKey();
    $keys[] = $newKey;
    saveKeys($keys);

    $message = "Rotation: New Key=$newKey";
    logEvent($message, getLastLogHash());

    // Remove the oldest key if the number of keys exceeds a limit
    if (count($keys) > 5) {
        array_shift($keys);
        saveKeys($keys);
    }
}

// Main service loop
function serviceLoop()
{
    $keys = loadKeys();
    if (empty($keys)) {
        $keys[] = generateKey();
        saveKeys($keys);
        logEvent("Initial Key Generation: " . end($keys));
    }

    $lastRotation = file_exists(STORAGE_DIR . '/last_rotation.txt')
        ? file_get_contents(STORAGE_DIR . '/last_rotation.txt')
        : 0;

    while (true) {
        $now = time();
        if ($now - $lastRotation >= ROTATION_INTERVAL) {
            rotateKeys($keys);
            file_put_contents(STORAGE_DIR . '/last_rotation.txt', $now);
            $lastRotation = $now;
        }

        // Simulate key access
        $role = rand(0, 1) == 0 ? 'admin' : 'service';
        $key = $keys[array_rand($keys)];
        accessKey($key, $role);

        sleep(1); // Simulate time passage
    }
}

// Start the service loop
serviceLoop();
?>
