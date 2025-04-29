<?php

/**
 * Automated Key Rotation and Auditing Service
 *
 * This standalone PHP script simulates a long-running service that manages
 * a secure lifecycle of API keys (or similar tokens). It implements:
 *  - Secure key generation (32-byte, 256-bit) using random_bytes()
 *  - Scheduled key rotation at configurable intervals
 *  - Key expiration and revocation
 *  - Audit logging with timestamp and a chained integrity hash (SHA-256)
 *  - Simple role-based key access simulation (admin vs. service account)
 *  - Log rotation to simulate file archival
 *
 * All data is stored in a local ./storage directory using plain files.
 * No external libraries, frameworks, or command-line tools are used.
 *
 * Intended for demonstration and educational purposes.
 *
 * ----------------------------------------------------------------------------------
 * IMPORTANT:
 * This script continuously loops and sleeps to simulate the passage of time.
 * You can stop it with Ctrl + C or by killing the process.
 * ----------------------------------------------------------------------------------
 */

/**
 * ============================================================================
 * CONFIGURATION SECTION
 * ============================================================================
 *
 * You can modify these constants to adjust behavior, file paths, and intervals.
 */

define('STORAGE_DIR',       __DIR__ . '/storage');   // Directory where keys and logs are stored
define('KEY_STORE_FILE',    STORAGE_DIR . '/keys.json');
define('USED_KEYS_FILE',    STORAGE_DIR . '/used_keys.json');
define('AUDIT_LOG_FILE',    STORAGE_DIR . '/audit.log');
define('LOG_ROTATION_LINES', 500); // Maximum lines in a log file before rotation
define('ROTATION_INTERVAL', 24 * 60 * 60); // 24 hours in "simulated" time
define('TIME_INCREMENT',    60 * 60); // Each loop iteration simulates this many seconds passing (1 hour)
define('SLEEP_SECONDS',     1);      // Real-time sleep between iterations (short for demo purposes)

/**
 * ============================================================================
 * HELPER FUNCTIONS
 * ============================================================================
 */

/**
 * Ensure that the ./storage directory exists.
 * Creates it if it does not exist.
 *
 * @return void
 */
function initializeStorageDirectory()
{
    if (!is_dir(STORAGE_DIR)) {
        mkdir(STORAGE_DIR, 0700, true);
    }
}

/**
 * Load the current key set from the JSON file, or create an empty structure if none exists.
 *
 * @return array The keys structure, e.g. ['admin' => [...], 'service' => [...]]
 */
function loadKeys()
{
    if (!file_exists(KEY_STORE_FILE)) {
        // Return an empty array if the file does not exist
        return [];
    }

    $content = file_get_contents(KEY_STORE_FILE);
    $keys = json_decode($content, true);
    if (!is_array($keys)) {
        // If file is corrupted, start fresh
        return [];
    }

    return $keys;
}

/**
 * Persist the key structure to the JSON file.
 *
 * @param array $keys
 * @return void
 */
function saveKeys(array $keys)
{
    file_put_contents(KEY_STORE_FILE, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Load the set of previously used keys to ensure no key value is ever reused.
 *
 * @return array Array of hex-encoded keys that have been used.
 */
function loadUsedKeys()
{
    if (!file_exists(USED_KEYS_FILE)) {
        return [];
    }

    $content = file_get_contents(USED_KEYS_FILE);
    $usedKeys = json_decode($content, true);
    if (!is_array($usedKeys)) {
        return [];
    }

    return $usedKeys;
}

/**
 * Save the updated list of used keys back to the JSON file.
 *
 * @param array $usedKeys
 * @return void
 */
function saveUsedKeys(array $usedKeys)
{
    file_put_contents(USED_KEYS_FILE, json_encode($usedKeys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Generate a new cryptographically secure 256-bit (32-byte) key.
 * Ensures it has never been used before (to comply with "no key reuse").
 *
 * @param array &$usedKeys Reference to the array of used keys.
 * @return string Returns a hex-encoded 32-byte key.
 */
function generateUniqueKey(array &$usedKeys)
{
    // Keep generating until we find one that has not been used
    while (true) {
        $rawKey = random_bytes(32);
        $hexKey = bin2hex($rawKey); // store as hex to avoid strange binary issues

        if (!in_array($hexKey, $usedKeys, true)) {
            // Mark this key as used and return it
            $usedKeys[] = $hexKey;
            return $hexKey;
        }
    }
}

/**
 * Retrieve the chain hash from the last log entry, or return a default if none.
 *
 * @return string
 */
function getLastChainHash()
{
    if (!file_exists(AUDIT_LOG_FILE)) {
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }

    // Attempt to read last line of the file
    $lines = file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        // If the log file is empty or unreadable
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }

    $lastLine = $lines[count($lines) - 1];
    // Format: prevHash, timestamp, event, details, thisHash
    $parts = explode(' | ', $lastLine);
    // The "thisHash" should be last
    if (count($parts) === 5) {
        return $parts[4];
    } else {
        // Fallback if something is malformed
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }
}

/**
 * Write a single event line to the audit log, chaining the new line's hash
 * with the previous line's hash.
 *
 * Log format for each line:
 *   prevChainHash | ISO8601timestamp | eventName | details | thisChainHash
 *
 * The new chain hash is derived as:
 *   thisChainHash = SHA256(prevChainHash . fullLineBeforeThisChain)
 *
 * @param string $event Short event name or label
 * @param string $details Additional info about the event
 * @return void
 */
function logEvent($event, $details)
{
    // Check log rotation first
    maybeRotateLog();

    $prevChainHash = getLastChainHash();
    $timestamp     = date('c');
    // We'll create the partial line that excludes the "thisChainHash"
    $partialLine   = $prevChainHash . ' | ' . $timestamp . ' | ' . $event . ' | ' . $details;
    $thisChainHash = hash('sha256', $partialLine);

    $fullLine = $partialLine . ' | ' . $thisChainHash . "\n";
    file_put_contents(AUDIT_LOG_FILE, $fullLine, FILE_APPEND);
}

/**
 * Check if the log file has exceeded the configured number of lines.
 * If so, rotate (archive) it.
 *
 * @return void
 */
function maybeRotateLog()
{
    if (!file_exists(AUDIT_LOG_FILE)) {
        return;
    }

    $lineCount = count(file(AUDIT_LOG_FILE));
    if ($lineCount < LOG_ROTATION_LINES) {
        return; // No rotation needed yet
    }

    // Archive current log
    $newName = STORAGE_DIR . '/audit-' . date('Ymd_His') . '.log';
    rename(AUDIT_LOG_FILE, $newName);

    // Start a fresh audit log by linking continuity with the last chain hash
    $oldLastChain = getLastChainHashFromFile($newName);
    $timestamp    = date('c');
    $partialLine  = $oldLastChain . ' | ' . $timestamp . ' | LOG_ROTATION | ' . 'Rotated log file';
    $thisChainHash = hash('sha256', $partialLine);
    $fullLine     = $partialLine . ' | ' . $thisChainHash . "\n";
    file_put_contents(AUDIT_LOG_FILE, $fullLine);
}

/**
 * Retrieve the chain hash from the last line of a specified log file.
 *
 * @param string $filePath Path to the archived log file
 * @return string
 */
function getLastChainHashFromFile($filePath)
{
    if (!file_exists($filePath)) {
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }
    $lastLine = $lines[count($lines) - 1];
    $parts = explode(' | ', $lastLine);
    if (count($parts) === 5) {
        return $parts[4];
    } else {
        return '0000000000000000000000000000000000000000000000000000000000000000';
    }
}

/**
 * Rotate (replace) all active keys with new ones. This function:
 *   1. Generates a new unique key for each role.
 *   2. Sets a new expiration time.
 *   3. Logs the key rotation event.
 *
 * @param array $keys Current keys array
 * @param int   $currentTime The current simulated time in seconds
 * @param array &$usedKeys   Reference to the used-keys array
 * @return array Updated keys array
 */
function rotateKeys(array $keys, $currentTime, array &$usedKeys)
{
    logEvent('KEY_ROTATION', 'Rotating all active keys');

    // For demonstration, we assume exactly two roles: "admin" and "service".
    // You could expand this to more roles if needed.
    $roles = ['admin', 'service'];

    foreach ($roles as $role) {
        $newKey = generateUniqueKey($usedKeys);
        $keys[$role] = [
            'key'        => $newKey,
            'created_at' => $currentTime,
            'expires_at' => $currentTime + ROTATION_INTERVAL
        ];
        logEvent('KEY_GENERATED', "Role={$role}, Key={$newKey}");
    }

    // Save updated used keys
    saveUsedKeys($usedKeys);

    return $keys;
}

/**
 * Simulate a request to access a particular role's key. Logs the event and
 * returns either the key or a notice of expiration.
 *
 * @param string $role Role name ("admin" or "service")
 * @param array  $keys Keys array
 * @param int    $currentTime Current simulated time in seconds
 * @return void
 */
function simulateAccess($role, array $keys, $currentTime)
{
    if (!isset($keys[$role])) {
        logEvent('KEY_ACCESS_FAILED', "Role={$role}, Reason=No key found");
        return;
    }

    $keyInfo = $keys[$role];
    if ($currentTime > $keyInfo['expires_at']) {
        // Key is expired
        logEvent('KEY_ACCESS_FAILED', "Role={$role}, Reason=Key expired");
        return;
    }

    // Key is valid; log the access
    $key = $keyInfo['key'];
    logEvent('KEY_ACCESS_SUCCESS', "Role={$role}, Key={$key}");
}

/**
 * ============================================================================
 * MAIN SERVICE LOOP
 * ============================================================================
 *
 * 1. Initialize environment (storage folders, log files, etc.)
 * 2. Load or create keys
 * 3. Set up a simulated clock
 * 4. Continuously loop:
 *       - Simulate some key access events for admin/service roles
 *       - Check if it's time to rotate (based on the simulated clock)
 *       - Sleep for SLEEP_SECONDS (real-time) to avoid a busy loop
 * 5. Press Ctrl + C to stop the script
 */

/**
 * Entry point for our daemon-like script.
 *
 * @return void
 */
function main()
{
    // 1. Make sure we have a storage directory
    initializeStorageDirectory();

    // 2. Load or create data
    $keys     = loadKeys();
    $usedKeys = loadUsedKeys();

    // If there's no log file yet, initialize it with a dummy line
    // so that getLastChainHash() will work properly.
    if (!file_exists(AUDIT_LOG_FILE)) {
        file_put_contents(AUDIT_LOG_FILE, '');
    }

    // 3. Set up simulated clock
    //    We'll start from the current real timestamp, but treat increments
    //    as if each iteration is an hour in "service time".
    $currentTime     = time();
    $nextRotateTime  = $currentTime;  // Force immediate rotate on first iteration to create keys if needed

    logEvent('SERVICE_START', 'Key rotation service started');

    // 4. Main loop
    while (true) {
        // Simulate some usage in each iteration
        simulateAccess('admin',   $keys, $currentTime);
        simulateAccess('service', $keys, $currentTime);

        // Check if we need to rotate
        if ($currentTime >= $nextRotateTime) {
            // Perform rotation
            $keys = rotateKeys($keys, $currentTime, $usedKeys);
            saveKeys($keys);

            // After rotation, schedule next rotation
            $nextRotateTime = $currentTime + ROTATION_INTERVAL;
        }

        // Sleep for a short period to avoid busy loop in real time
        sleep(SLEEP_SECONDS);

        // Advance our simulated clock by TIME_INCREMENT (1 hour by default)
        $currentTime += TIME_INCREMENT;
    }
}

/**
 * Call the main function to start the service.
 */
main();
