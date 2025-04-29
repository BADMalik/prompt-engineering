<?php
// Configuration constants
define('KEY_LENGTH', 32); // 256-bit key length
define('ROTATION_INTERVAL', 86400); // 24 hours in seconds
define('LOG_ROTATION_THRESHOLD', 500); // Number of lines before log rotation
define('STORAGE_DIR', './storage');
define('KEYS_FILE', STORAGE_DIR . '/keys.txt');
define('AUDIT_LOG_FILE', STORAGE_DIR . '/audit.log');
define('ARCHIVED_LOG_FILE', STORAGE_DIR . '/audit.log.archive');

// Ensure storage directory exists
if (!file_exists(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

// Function to generate a secure random key
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

// Function to log an event to the audit log
function logEvent($message)
{
    $logEntry = date('c') . ' - ' . $message;
    $prevHash = file_exists(AUDIT_LOG_FILE) ? hash_file('sha256', AUDIT_LOG_FILE) : '';
    $logEntry .= ' - ' . $prevHash;
    file_put_contents(AUDIT_LOG_FILE, $logEntry . PHP_EOL, FILE_APPEND);
    checkLogRotation();
}

// Function to check and perform log rotation if needed
function checkLogRotation()
{
    $lines = count(file(AUDIT_LOG_FILE));
    if ($lines >= LOG_ROTATION_THRESHOLD) {
        rename(AUDIT_LOG_FILE, ARCHIVED_LOG_FILE);
        file_put_contents(AUDIT_LOG_FILE, ''); // Clear the current log file
    }
}

// Function to simulate key access (role-based)
function accessKey($role, $key)
{
    $message = "Access by $role to key $key";
    logEvent($message);
}

// Function to rotate keys
function rotateKeys(&$keys)
{
    $newKey = generateKey();
    $keys[] = $newKey;
    saveKeys($keys);
    logEvent('Key rotated: ' . $newKey);
}

// Main service loop
function serviceLoop()
{
    $lastRotation = 0;
    while (true) {
        $currentTime = time();
        $keys = loadKeys();

        if ($currentTime - $lastRotation >= ROTATION_INTERVAL) {
            rotateKeys($keys);
            $lastRotation = $currentTime;
        }

        // Simulate key access
        $role = ($currentTime % 2 == 0) ? 'admin' : 'service';
        if (!empty($keys)) {
            $key = end($keys); // Access the latest key
            accessKey($role, $key);
        }

        sleep(1); // Simulate time passage
    }
}

// Start the service
serviceLoop();
