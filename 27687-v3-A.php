<?php
/**
 * Automated Key Rotation and Auditing Service
 *
 * This standalone PHP script simulates a daemon-like service that performs:
 *   1. Secure key generation (using random_bytes).
 *   2. Scheduled key rotation at configurable intervals.
 *   3. Enforced key expiration and revocation.
 *   4. Append-only audit logging with SHA-256 hash chaining.
 *   5. Role-based key access simulation (admin vs. service).
 *   6. Integrity checks to detect potential tampering (via chain hashing).
 *   7. Log rotation after a set number of lines.
 *
 * ---------------------------------------------------------------------------
 * REQUIREMENTS & ASSUMPTIONS:
 *   - Native PHP only (no external libraries).
 *   - PHP 7.1+ recommended (for random_bytes).
 *   - Creates local storage under "./storage" (keys + logs).
 *   - Long-running service simulated via while-loop and sleep().
 *   - Demonstrates best practices in secret handling and log integrity.
 *   - All timestamping uses date('c') (ISO 8601).
 *   - For demonstration purposes, "rotation interval" and "log rotation size"
 *     are greatly reduced so the script can show behavior quickly.
 *   - This script is self-contained and has no external configuration.
 * ---------------------------------------------------------------------------
 *
 * HOW TO RUN:
 *   1. Ensure you have permission to create and write to the "./storage" folder.
 *   2. Run via CLI: php automated_key_rotation.php
 *   3. Observe console output and the created files in "./storage".
 *
 * NOTE:
 *   - This script will terminate after a fixed number of simulation days
 *     (MAX_SIM_DAYS) to keep logs manageable.
 *   - In a real production setup, remove or modify such termination to run indefinitely.
 *   - All stored secrets and logs are plain files for demonstration only.
 *     In a real system, you would use secure storage facilities or secure DB.
 */

// ---------------------------------------------------------------------------
// 1) CONFIGURATION CONSTANTS
// ---------------------------------------------------------------------------

/**
 * Directory paths for storage (keys + logs).
 */
define('STORAGE_DIR', __DIR__ . '/storage');
define('LOG_DIR', STORAGE_DIR . '/logs');

/**
 * File in which all historical keys are stored (JSON).
 * Structure example:
 * [
 *   {
 *     "id": 1,
 *     "key_hex": "...",
 *     "created_day": 0,
 *     "status": "active|revoked|expired"
 *   }, ...
 * ]
 */
define('KEYS_FILE', STORAGE_DIR . '/keys.json');

/**
 * Current audit log path. Will be rotated upon reaching LOG_ROTATION_LINES.
 */
define('CURRENT_LOG_FILE', LOG_DIR . '/audit_current.log');

/**
 * After how many log lines do we rotate the audit log file.
 * (Set small for demonstration; typical might be 500 or more.)
 */
define('LOG_ROTATION_LINES', 10);

/**
 * Simulated day interval to rotate keys.
 * (For demonstration, 1 day = 1 simulation iteration.)
 */
define('ROTATION_INTERVAL_DAYS', 1);

/**
 * Total number of simulated days before the script terminates.
 * (In an actual daemon, you might remove this limit.)
 */
define('MAX_SIM_DAYS', 7);

/**
 * Sleep time (seconds) between simulation days. Adjust for demonstration.
 */
define('SLEEP_SECONDS_PER_ITERATION', 2);

// ---------------------------------------------------------------------------
// 2) INITIAL ENVIRONMENT SETUP
// ---------------------------------------------------------------------------

// Create storage directories if not exist
if (!is_dir(STORAGE_DIR)) {
    mkdir(STORAGE_DIR, 0700, true);
}
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0700, true);
}

// Load any existing keys from the KEYS_FILE; otherwise, create an empty array.
$keys = load_keys();

// Global chain-hash memory for the audit log. We read the last log entry to continue the chain.
$prevLogHash = get_last_log_hash(CURRENT_LOG_FILE);

// Track how many lines are currently in the active log file (for rotation).
$currentLogLineCount = get_log_line_count(CURRENT_LOG_FILE);

// Keep track of the last day (in simulation) we performed a key rotation.
$lastRotationDay = get_last_rotation_day($keys);

// Counters for simulation
$simDay = get_highest_created_day($keys); // start from the highest known "day" in case script restarts

// ---------------------------------------------------------------------------
// 3) FUNCTION DEFINITIONS
// ---------------------------------------------------------------------------

/**
 * Loads the list of keys from KEYS_FILE (JSON).
 * If the file doesn't exist or is invalid, returns an empty array.
 *
 * @return array
 */
function load_keys()
{
    if (!file_exists(KEYS_FILE)) {
        return [];
    }
    $contents = file_get_contents(KEYS_FILE);
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

/**
 * Saves the list of keys to KEYS_FILE in JSON format.
 *
 * @param array $keys
 * @return void
 */
function save_keys(array $keys)
{
    file_put_contents(KEYS_FILE, json_encode($keys, JSON_PRETTY_PRINT));
    // As a best practice, consider restricting file permissions if possible.
}

/**
 * Retrieve the highest "day" seen among all keys to continue from that day
 * in case the script restarts. Returns 0 if no keys exist.
 *
 * @param array $keys
 * @return int
 */
function get_highest_created_day(array $keys)
{
    $maxDay = 0;
    foreach ($keys as $k) {
        if (isset($k['created_day']) && $k['created_day'] > $maxDay) {
            $maxDay = $k['created_day'];
        }
    }
    return $maxDay;
}

/**
 * Get the last rotation day from the keys list.
 * The assumption is that each newly-generated key for rotation
 * marks the day it was created. We simply find the max created_day among 'active' or 'expired' keys.
 *
 * @param array $keys
 * @return int
 */
function get_last_rotation_day(array $keys)
{
    $maxDay = 0;
    foreach ($keys as $k) {
        if (isset($k['created_day']) && $k['created_day'] > $maxDay) {
            $maxDay = $k['created_day'];
        }
    }
    return $maxDay;
}

/**
 * Generate a new 256-bit (32-byte) key in hex form, ensuring it hasn't been used before.
 * Returns a 64-character hex string.
 *
 * @param array $keys Current list of keys to verify uniqueness.
 * @return string
 */
function generate_unique_key_hex(array $keys)
{
    // Collect all existing key_hex values to ensure no reuse.
    $existingKeys = [];
    foreach ($keys as $k) {
        $existingKeys[$k['key_hex']] = true;
    }

    do {
        // Generate 32 cryptographically secure random bytes
        $random = random_bytes(32);
        $keyHex = bin2hex($random);
    } while (isset($existingKeys[$keyHex]));

    return $keyHex;
}

/**
 * Returns the ID of the currently active key, or null if none are active.
 *
 * @param array $keys
 * @return int|null
 */
function get_active_key_id(array $keys)
{
    // The "last" active key in the array is considered current if multiple are active.
    // In real life, you'd maintain an explicit pointer or single "active" record.
    $activeId = null;
    foreach ($keys as $k) {
        if ($k['status'] === 'active') {
            $activeId = $k['id'];
        }
    }
    return $activeId;
}

/**
 * Rotate the active key (expire the old one, create a new one).
 * - Sets old key status to "expired".
 * - Generates new key with status "active".
 * - Logs the rotation event.
 *
 * @param array $keys
 * @param int $simDay
 * @param string &$prevLogHash
 * @param int &$currentLogLineCount
 * @return array Returns updated keys array.
 */
function rotate_key(array $keys, $simDay, &$prevLogHash, &$currentLogLineCount)
{
    $timestamp = date('c');

    // Mark any currently active key as expired
    $activeId = get_active_key_id($keys);
    if ($activeId !== null) {
        foreach ($keys as &$k) {
            if ($k['id'] === $activeId) {
                $k['status'] = 'expired';
                log_event('ROTATION', 'system', "Key ID {$k['id']} expired.", $prevLogHash, $currentLogLineCount);
                break;
            }
        }
        unset($k);
    }

    // Generate a fresh key
    $newKeyHex = generate_unique_key_hex($keys);
    $newId = count($keys) + 1; // simplistic ID assignment
    $keys[] = [
        'id' => $newId,
        'key_hex' => $newKeyHex,
        'created_day' => $simDay,
        'status' => 'active'
    ];

    // Record the creation
    log_event('ROTATION', 'system', "New key ID $newId created.", $prevLogHash, $currentLogLineCount);

    // Save changes
    save_keys($keys);

    return $keys;
}

/**
 * Revoke a specific key by ID (if not already revoked/expired).
 * Logs the revocation event.
 *
 * @param array $keys
 * @param int $keyId
 * @param string &$prevLogHash
 * @param int &$currentLogLineCount
 * @return array
 */
function revoke_key(array $keys, $keyId, &$prevLogHash, &$currentLogLineCount)
{
    foreach ($keys as &$k) {
        if ($k['id'] === $keyId && in_array($k['status'], ['active', 'expired'], true)) {
            $k['status'] = 'revoked';
            log_event('REVOKE', 'admin', "Key ID $keyId revoked.", $prevLogHash, $currentLogLineCount);
        }
    }
    unset($k);

    save_keys($keys);
    return $keys;
}

/**
 * Log an event to the current audit log, appending a chain hash.
 * Each line format:
 *   timestamp|eventType|role|detail|prevHash|currentHash
 *
 *   where:
 *     - currentHash = SHA-256 of (timestamp . eventType . role . detail . prevHash)
 *     - prevHash is the chain link to the previous line's currentHash
 *
 * @param string $eventType (e.g., 'ROTATION', 'ACCESS', 'REVOKE', 'INFO', etc.)
 * @param string $role (e.g., 'admin', 'service', 'system')
 * @param string $detail Additional info about the event.
 * @param string &$prevLogHash The previous line's final hash; updated after logging.
 * @param int &$currentLogLineCount The line count in the current log file.
 * @return void
 */
function log_event($eventType, $role, $detail, &$prevLogHash, &$currentLogLineCount)
{
    $timestamp = date('c');
    $dataString = $timestamp . $eventType . $role . $detail . $prevLogHash;
    $currentHash = hash('sha256', $dataString);

    $line = "$timestamp|$eventType|$role|$detail|$prevLogHash|$currentHash\n";
    file_put_contents(CURRENT_LOG_FILE, $line, FILE_APPEND);

    // Update chain references in memory
    $prevLogHash = $currentHash;
    $currentLogLineCount++;

    // Check if we need to rotate the log file.
    if ($currentLogLineCount >= LOG_ROTATION_LINES) {
        rotate_log_file($prevLogHash, $currentLogLineCount);
    }
}

/**
 * Retrieve the final chain-hash from the last line of the given log file.
 * If file doesn't exist or is empty, returns 'INITIAL'.
 *
 * @param string $filePath
 * @return string
 */
function get_last_log_hash($filePath)
{
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return 'INITIAL';
    }
    // Read file from end to find last line
    $fp = fopen($filePath, 'r');
    fseek($fp, -1, SEEK_END);

    // Move backward until we find a newline
    $pos = ftell($fp);
    while ($pos > 0) {
        $char = fgetc($fp);
        if ($char === "\n") {
            break;
        }
        fseek($fp, --$pos, SEEK_SET);
    }

    $lastLine = fgets($fp); // read the final line
    fclose($fp);

    if (!$lastLine) {
        // No content
        return 'INITIAL';
    }

    // The line format:
    // "timestamp|eventType|role|detail|prevHash|currentHash"
    $parts = explode('|', trim($lastLine));
    if (count($parts) < 6) {
        return 'INITIAL';
    }

    // The final element is the currentHash
    return $parts[5];
}

/**
 * Count how many lines are present in the current audit file.
 *
 * @param string $filePath
 * @return int
 */
function get_log_line_count($filePath)
{
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return 0;
    }
    $lines = 0;
    $handle = fopen($filePath, "r");
    while (!feof($handle)) {
        $buffer = fgets($handle);
        if ($buffer !== false) {
            $lines++;
        }
    }
    fclose($handle);
    return $lines;
}

/**
 * Rotate the current log file when it exceeds LOG_ROTATION_LINES lines.
 * The chain continues to the new log file, using the last line's hash from the
 * old log as the "prevHash" for the first line of the new file.
 *
 * @param string &$prevLogHash The chain hash at rotation moment.
 * @param int &$currentLogLineCount
 * @return void
 */
function rotate_log_file(&$prevLogHash, &$currentLogLineCount)
{
    // Rename current log
    $timestamp = date('Ymd_His');
    $archivePath = LOG_DIR . '/audit_' . $timestamp . '.log';
    rename(CURRENT_LOG_FILE, $archivePath);

    // Start a fresh log file
    file_put_contents(CURRENT_LOG_FILE, ""); // create empty

    // The chain continues; the first line in the new file will carry forward $prevLogHash.
    $currentLogLineCount = 0;

    // Record an INFO event to the new file about the rotation
    log_event('INFO', 'system', "Log rotation occurred; new file started.", $prevLogHash, $currentLogLineCount);
}

/**
 * Example function to simulate a "service" role accessing the active key.
 *
 * @param array $keys
 * @param string &$prevLogHash
 * @param int &$currentLogLineCount
 * @return void
 */
function service_access_key(array $keys, &$prevLogHash, &$currentLogLineCount)
{
    $activeId = get_active_key_id($keys);
    if ($activeId === null) {
        // No active key
        log_event('ACCESS', 'service', "No active key available!", $prevLogHash, $currentLogLineCount);
        return;
    }
    // For demonstration, we won't actually do anything with the key except log.
    log_event('ACCESS', 'service', "Accessed key ID $activeId", $prevLogHash, $currentLogLineCount);
}

/**
 * Example function to simulate an "admin" checking the active key or revoking an older key.
 *
 * @param array $keys
 * @param string &$prevLogHash
 * @param int &$currentLogLineCount
 * @return array Possibly updated keys if revocations occurred.
 */
function admin_access_key(array $keys, &$prevLogHash, &$currentLogLineCount)
{
    $activeId = get_active_key_id($keys);
    if ($activeId === null) {
        log_event('ACCESS', 'admin', "No active key for admin to check.", $prevLogHash, $currentLogLineCount);
    } else {
        log_event('ACCESS', 'admin', "Checking current active key ID $activeId.", $prevLogHash, $currentLogLineCount);
    }
    // Return keys unchanged for now
    return $keys;
}

// ---------------------------------------------------------------------------
// 4) MAIN SIMULATION LOOP
// ---------------------------------------------------------------------------

/**
 * This loop simulates "days" passing, rotating keys on schedule,
 * and logging random admin/service activities.
 */

// Ensure at least one key is present if none exist (initial).
if (get_active_key_id($keys) === null) {
    $keys = rotate_key($keys, $simDay, $prevLogHash, $currentLogLineCount);
    $lastRotationDay = $simDay;
}

// For demonstration, we run up to MAX_SIM_DAYS and stop.
while ($simDay < MAX_SIM_DAYS) {
    // Log that a new day has started in the simulation
    log_event('INFO', 'system', "Simulation day $simDay start.", $prevLogHash, $currentLogLineCount);

    // Check if we need to rotate keys based on ROTATION_INTERVAL_DAYS
    if (($simDay - $lastRotationDay) >= ROTATION_INTERVAL_DAYS) {
        $keys = rotate_key($keys, $simDay, $prevLogHash, $currentLogLineCount);
        $lastRotationDay = $simDay;
    }

    // Randomly simulate either admin or service accessing the key
    if ($simDay % 2 === 0) {
        // Even days: admin checks
        $keys = admin_access_key($keys, $prevLogHash, $currentLogLineCount);
    } else {
        // Odd days: service attempts to access
        service_access_key($keys, $prevLogHash, $currentLogLineCount);
    }

    // On day 3, demonstrate revoking an old key (if it exists).
    if ($simDay === 3) {
        // For demonstration, revoke key ID 1 if it exists
        $keys = revoke_key($keys, 1, $prevLogHash, $currentLogLineCount);
    }

    // Sleep to simulate time passage in real scenario
    sleep(SLEEP_SECONDS_PER_ITERATION);

    // Advance simulation day
    $simDay++;
}

// Final log to indicate shutdown
log_event('INFO', 'system', "Reached MAX_SIM_DAYS=$simDay, shutting down simulation.", $prevLogHash, $currentLogLineCount);

/**
 * End of script.
 * ---------------------------------------------------------------------------
 * All relevant files will remain in the './storage' folder:
 *   - keys.json (keys data)
 *   - logs/audit_current.log (active log)
 *   - logs/audit_*.log (rotated logs)
 */