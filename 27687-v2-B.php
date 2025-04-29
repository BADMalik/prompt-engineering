<?php
// Configuration constants
define('KEY_LENGTH', 32); // 256-bit key length
define('ROTATION_INTERVAL', 86400); // 24 hours in seconds
define('LOG_ROTATION_THRESHOLD', 500); // Number of lines before log rotation
define('STORAGE_DIR', './storage');
define('KEYS_FILE', STORAGE_DIR . '/keys.txt');
define('LOG_FILE', STORAGE_DIR . '/audit.log');

// Ensure storage directory exists
if (!file_exists(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0755, true);
}

// Function to generate a new cryptographically secure key
function generateKey()
{
    return bin2hex(random_bytes(KEY_LENGTH));
}

// Function to read keys from file
function readKeys()
{
    if (!file_exists(KEYS_FILE)) {
        return [];
    }
    return array_filter(explode(PHP_EOL, file_get_contents(KEYS_FILE)));
}

// Function to save keys to file
function saveKeys($keys)
{
    file_put_contents(KEYS_FILE, implode(PHP_EOL, $keys));
}

// Function to log an event with timestamp and previous log hash
function logEvent($message)
{
    $logFile = fopen(LOG_FILE, 'a');
    $timestamp = date('c');
    $previousHash = filesize(LOG_FILE) > 0 ? hash('sha256', file_get_contents(LOG_FILE)) : '';

    fwrite($logFile, "$timestamp - $message - Previous Hash: $previousHash\n");
    fclose($logFile);

    // Check for log rotation
    $lines = explode(PHP_EOL, file_get_contents(LOG_FILE));
    if (count($lines) >= LOG_ROTATION_THRESHOLD) {
        rotateLog();
    }
}

// Function to rotate log file
function rotateLog()
{
    $logContent = file_get_contents(LOG_FILE);
    $logFileName = date('Y-m-d-His') . '_' . LOG_FILE;
    file_put_contents($logFileName, $logContent);
    file_put_contents(LOG_FILE, ''); // Clear current log file
}

// Function to simulate key access (role-based)
function accessKey($role)
{
    $keys = readKeys();
    if (empty($keys)) {
        logEvent("No keys available for $role access");
        return;
    }

    $key = $keys[array_rand($keys)];
    logEvent("$role accessed key: $key");
}

// Function to rotate keys
function rotateKeys()
{
    $keys = readKeys();
    $newKeys = array_map('generateKey', array_fill(0, count($keys), null));

    logEvent("Keys rotated: " . implode(', ', $newKeys));
    saveKeys($newKeys);
}

// Main service loop
while (true) {
    $currentTime = time();

    // Simulate key access every hour
    if ($currentTime % 3600 == 0) {
        accessKey('service');
        accessKey('admin');
    }

    // Simulate key rotation every 24 hours
    if ($currentTime % ROTATION_INTERVAL == 0) {
        rotateKeys();
    }

    sleep(1); // Simulate time passage
}
