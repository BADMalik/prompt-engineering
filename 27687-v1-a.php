<?php

/**
 * Standalone PHP Service for Automated Key Rotation and Auditing
 *
 * This script simulates a long-running daemon that manages API keys (or similar tokens)
 * in a multi-user environment. It demonstrates:
 *
 *  1. Secure key generation using cryptographically secure random_bytes().
 *  2. Scheduled key rotation at a configurable interval (default 24-hour lifecycle).
 *  3. Enforced key expiration and revocation logic.
 *  4. Audit logging of all key access and rotation events using a hash chain for integrity.
 *  5. Role-based key access simulation (admin vs. service).
 *  6. Log rotation after reaching a certain number of lines (e.g., 500).
 *
 * Data persistence is handled via local file storage under ./storage,
 * without using any external databases or libraries.
 *
 * NOTE: This script is designed for demonstration purposes to illustrate key rotation,
 * audit logging, and integrity checks. It is not intended for production use.
 *
 * Usage:
 *  1) Make sure the script is executable: chmod +x key_rotation_service.php
 *  2) Run it: ./key_rotation_service.php
 *  3) It will run indefinitely. Stop it with Ctrl+C or kill the process as needed.
 *
 * All functions are written procedurally, with clear input/output responsibilities.
 * Sleep calls and simulated time progression are used instead of real cron jobs.
 */

/* --------------------------------------------------
 * CONFIGURATION
 * --------------------------------------------------
 * Adjust these constants to change the behavior.
 */

// Directory for all storage files.
define('STORAGE_DIR', __DIR__ . '/storage');

// Lifetime of each key in simulated seconds (24 hours = 86400).
define('KEY_LIFETIME_SECONDS', 86400);

// How many lines of audit log before we rotate/archival.
define('AUDIT_LOG_MAX_LINES', 500);

// How many real-time seconds to sleep on each loop iteration.
define('MAIN_LOOP_SLEEP_SECONDS', 2);

// How many simulated seconds pass on each loop iteration.
define('SIMULATED_TIME_STEP', 60);

// For demonstration, we will run an indefinite loop. 
// You may limit the number of iterations if needed.
define('MAX_ITERATIONS', 0); // 0 means run forever.

/* --------------------------------------------------
 * GLOBAL STATE (run-time memory)
 * --------------------------------------------------
 * These variables hold in-memory states, updated on each loop. 
 * They are persisted to disk as needed.
 */

$currentSimulatedTime = time();       // We'll start from real system time, then increment artificially.
$lastLogHash = '';                    // Stores the hash of the last log entry for chain integrity.
$iterationCount = 0;                  // Counts how many loop cycles have passed.

/**
 * In-memory list of used key values to ensure no key is ever reused.
 * This is also persisted case a script restarts (see initUsedKeyValues()).
 */
$usedKeyValues = [];

/**
 * Cached copy of keys read from ./storage/keys.json.
 * We will read once at startup and write changes back on each modification.
 * Keys are stored as an associative array of arrays.
 * Each key's structure:
 * [
 *   'id'        => string  (unique identifier of the key),
 *   'value'     => string  (raw 32-byte binary, base64-encoded when stored on disk),
 *   'created'   => int     (simulated timestamp of creation),
 *   'expiry'    => int     (simulated timestamp of expiry),
 *   'role'      => string  (e.g., 'admin' or 'service'),
 *   'status'    => string  ('active' or 'revoked')
 * ]
 */
$currentKeys = [];

/* ==================================================
 *                  INITIAL SETUP
 * ================================================== */

/**
 * Ensure the storage directory and required files exist.
 * Initialize key store, used-key store, and audit log if needed.
 *
 * @return void
 */
function initStorage()
{
    global $currentKeys, $usedKeyValues, $lastLogHash;

    if (!is_dir(STORAGE_DIR)) {
        mkdir(STORAGE_DIR, 0700, true);
    }

    // Initialize keys.json
    $keysFile = STORAGE_DIR . '/keys.json';
    if (!file_exists($keysFile)) {
        file_put_contents($keysFile, json_encode([]));
    }
    $content = file_get_contents($keysFile);
    $currentKeys = json_decode($content, true);
    if (!is_array($currentKeys)) {
        $currentKeys = [];
    }

    // Initialize used_keys.json
    $usedKeysFile = STORAGE_DIR . '/used_keys.json';
    if (!file_exists($usedKeysFile)) {
        file_put_contents($usedKeysFile, json_encode([]));
    }
    $usedContent = file_get_contents($usedKeysFile);
    $tempUsedKeys = json_decode($usedContent, true);
    if (is_array($tempUsedKeys)) {
        $GLOBALS['usedKeyValues'] = $tempUsedKeys;
    }

    // Initialize (or find) the audit log and last hash
    initAuditLog();
}

/**
 * Initialize or load the audit log.
 * We attempt to read the last line to recover the last hash for chain continuity.
 *
 * @return void
 */
function initAuditLog()
{
    global $lastLogHash;

    $logFile = getAuditLogFilename();

    // If the log file does not exist, create it empty.
    if (!file_exists($logFile)) {
        file_put_contents($logFile, '');
        $lastLogHash = ''; // Fresh chain
    } else {
        // Read the last line to recover the final hash in the chain.
        $lastLine = getLastLine($logFile);
        if ($lastLine !== null) {
            $parts = explode('|', $lastLine);
            if (count($parts) === 5) {
                // The last element is the "thisHash"
                $lastLogHash = trim($parts[4]);
            }
        }
    }
}

/**
 * Returns the current audit log filename.
 * We store logs in STORAGE_DIR/audit.log for the active file.
 *
 * @return string
 */
function getAuditLogFilename()
{
    return STORAGE_DIR . '/audit.log';
}

/**
 * Get the last line of a file safely.
 *
 * @param string $filename
 * @return string|null
 */
function getLastLine($filename)
{
    if (!file_exists($filename) || filesize($filename) === 0) {
        return null;
    }

    $fp = fopen($filename, 'r');
    fseek($fp, -1, SEEK_END);
    $pos = ftell($fp);
    $line = '';

    // Read backwards until we find a newline or reach the beginning of file.
    while ($pos >= 0) {
        $char = fgetc($fp);
        if ($char === "\n") {
            break;
        }
        $line = $char . $line;
        fseek($fp, $pos--, SEEK_SET);
    }
    fclose($fp);

    return trim($line) === '' ? null : $line;
}

/* ==================================================
 *             KEY ACCESS AND GENERATION
 * ================================================== */

/**
 * Generate a new 256-bit (32-byte) key for the specified role.
 * Ensures no reuse of previously generated key values.
 * The new key is set to expire after KEY_LIFETIME_SECONDS.
 *
 * @param int    $currentTime  Simulated timestamp.
 * @param string $role         Either 'admin' or 'service' for demonstration.
 * @return array               The newly created key structure.
 */
function generateKey($currentTime, $role)
{
    global $usedKeyValues;

    // We must ensure random_bytes(32) is never repeated.
    do {
        $rawKey = random_bytes(32);  // 256-bit key
    } while (in_array(base64_encode($rawKey), $usedKeyValues, true));

    // Mark this raw key as used
    $usedKeyValues[] = base64_encode($rawKey);
    saveUsedKeyValues();

    // Build key structure
    $newKey = [
        'id'      => uniqid('key_', true),
        'value'   => base64_encode($rawKey),
        'created' => $currentTime,
        'expiry'  => $currentTime + KEY_LIFETIME_SECONDS,
        'role'    => $role,
        'status'  => 'active'
    ];

    return $newKey;
}

/**
 * Save the current list of used key values to disk.
 * This ensures no duplication across script restarts.
 *
 * @return void
 */
function saveUsedKeyValues()
{
    global $usedKeyValues;
    $file = STORAGE_DIR . '/used_keys.json';
    file_put_contents($file, json_encode($usedKeyValues, JSON_PRETTY_PRINT));
}

/**
 * Store the current in-memory keys to disk (keys.json).
 *
 * @return void
 */
function saveAllKeys()
{
    global $currentKeys;
    $file = STORAGE_DIR . '/keys.json';
    file_put_contents($file, json_encode($currentKeys, JSON_PRETTY_PRINT));
}

/* ==================================================
 *                KEY LIFECYCLE
 * ================================================== */

/**
 * Check all active keys for expiration. If any are expired, revoke them and
 * immediately generate a new key for the same role.
 *
 * @param int $currentTime  Simulated timestamp.
 * @return void
 */
function rotateExpiredKeys($currentTime)
{
    global $currentKeys;

    foreach ($currentKeys as $index => $key) {
        if ($key['status'] === 'active' && $currentTime >= $key['expiry']) {
            // Revoke the old key
            $currentKeys[$index]['status'] = 'revoked';
            logEvent($currentTime, 'KEY_REVOKE', 'Key revoked: ' . $key['id']);

            // Generate a new key for the same role
            $newKey = generateKey($currentTime, $key['role']);
            $currentKeys[] = $newKey;
            saveAllKeys();
            logEvent($currentTime, 'KEY_GENERATE', 'Generated new key ' . $newKey['id'] . ' for role ' . $key['role']);
        }
    }
}

/* ==================================================
 *               ROLE-BASED ACCESS
 * ================================================== */

/**
 * Simulate an "access" request by a role to a key. 
 * This checks for an active key of matching role and logs an event.
 *
 * Note: In a real system, you'd implement more granular ACL checks.
 *
 * @param int    $currentTime
 * @param string $requestedRole  The role requesting access.
 * @return void
 */
function simulateKeyAccess($currentTime, $requestedRole)
{
    global $currentKeys;

    // Filter for active keys belonging to the requested role
    $candidateKeys = array_filter($currentKeys, function ($k) use ($requestedRole) {
        return ($k['role'] === $requestedRole && $k['status'] === 'active');
    });

    if (count($candidateKeys) === 0) {
        // No active key for this role
        logEvent($currentTime, 'ACCESS_DENIED', "No active key for role [$requestedRole].");
        return;
    }

    // Just pick any active key (for demonstration).
    $randomKey = array_values($candidateKeys)[array_rand($candidateKeys)];
    logEvent($currentTime, 'KEY_ACCESS', "Role [$requestedRole] accessed key " . $randomKey['id']);
}

/* ==================================================
 *            AUDIT LOGGING AND INTEGRITY
 * ================================================== */

/**
 * Write a log event with a chain-hash to the active audit log file.
 * Format of each line:
 *   ISO8601_TIMESTAMP | EVENT_TYPE | DETAIL | PREV_HASH | THIS_HASH
 *
 * @param int    $currentTime
 * @param string $eventType
 * @param string $details
 * @return void
 */
function logEvent($currentTime, $eventType, $details)
{
    global $lastLogHash;

    // Rotate the log file if needed
    checkAndRotateAuditLog();

    $logFile    = getAuditLogFilename();
    $timestamp  = date('c', $currentTime); // ISO 8601
    $prevHash   = $lastLogHash;           // chain continuity
    $rawData    = $prevHash . $timestamp . $eventType . $details;
    $thisHash   = hash('sha256', $rawData);

    $line = $timestamp . '|' . $eventType . '|' . $details . '|' . $prevHash . '|' . $thisHash . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);

    // Update chain pointer
    $lastLogHash = $thisHash;
}

/**
 * Check how many lines are in the current audit log, and rotate if it exceeds AUDIT_LOG_MAX_LINES.
 * Rotation means we rename the existing audit.log and start a fresh file, preserving chain continuity.
 *
 * @return void
 */
function checkAndRotateAuditLog()
{
    global $lastLogHash;

    $logFile = getAuditLogFilename();

    // If file doesn't exist or is empty, no rotation needed.
    if (!file_exists($logFile)) {
        return;
    }

    $lineCount = 0;
    $handle = fopen($logFile, 'r');
    while (!feof($handle)) {
        $buffer = fgets($handle);
        if ($buffer !== false) {
            $lineCount++;
        }
    }
    fclose($handle);

    if ($lineCount >= AUDIT_LOG_MAX_LINES) {
        // We rotate
        $newName = STORAGE_DIR . '/audit.log.' . date('Ymd_His');
        rename($logFile, $newName);

        // Create a fresh log file
        file_put_contents($logFile, '');

        // Read the last line from the archived file to continue chain
        $lastLine = getLastLine($newName);
        if ($lastLine !== null) {
            $parts = explode('|', $lastLine);
            if (count($parts) === 5) {
                // The last element is the "thisHash"
                $lastLogHash = trim($parts[4]);
            }
        }
    }
}

/* ==================================================
 *             MAIN LOOP SIMULATION
 * ================================================== */

/**
 * The main loop simulates the passage of time, handles key rotation, 
 * and random role-based access to demonstrate auditing and lifecycle.
 *
 * @return void
 */
function mainLoop()
{
    global $currentSimulatedTime, $iterationCount;

    while (true) {
        $iterationCount++;

        // Rotate any expired keys
        rotateExpiredKeys($currentSimulatedTime);

        // Randomly simulate an admin or service action.
        simulateRandomAction($currentSimulatedTime);

        // Sleep to represent real-time passing, then increment simulated time
        sleep(MAIN_LOOP_SLEEP_SECONDS);
        $currentSimulatedTime += SIMULATED_TIME_STEP;

        // If there's a max iteration limit, break after reaching it (0 means infinite).
        if (MAX_ITERATIONS > 0 && $iterationCount >= MAX_ITERATIONS) {
            break;
        }
    }
}

/**
 * Simulate a random action (admin or service).
 * Admin might generate new keys or do nothing. 
 * Service might access a key or do nothing.
 *
 * @param int $currentTime
 * @return void
 */
function simulateRandomAction($currentTime)
{
    // We'll pick a random number from 0 to 99
    $random = mt_rand(0, 99);

    // Let's say there's a 20% chance an admin generates a new key,
    // 70% chance we do a service access,
    // 10% chance we do no action this iteration.
    if ($random < 20) {
        // Admin generates a new key
        $newKey = generateKey($currentTime, 'admin');
        global $currentKeys;
        $currentKeys[] = $newKey;
        saveAllKeys();
        logEvent($currentTime, 'KEY_GENERATE', 'Generated new admin key ' . $newKey['id']);
    } elseif ($random < 90) {
        // 70% chance service tries to access a key
        simulateKeyAccess($currentTime, 'service');
    } else {
        // Do nothing (idle)
        logEvent($currentTime, 'NO_OP', 'No operation performed.');
    }
}

/* ==================================================
 *                   ENTRY POINT
 * ================================================== */

// 1. Initialize storage, load data, ensure environment is ready
initStorage();

// 2. Optional: we might generate an initial admin and service key if none exist
if (empty($currentKeys)) {
    // Create one admin key
    $admKey = generateKey(time(), 'admin');
    $currentKeys[] = $admKey;
    logEvent(time(), 'KEY_GENERATE', 'Generated initial admin key: ' . $admKey['id']);

    // Create one service key
    $svcKey = generateKey(time(), 'service');
    $currentKeys[] = $svcKey;
    logEvent(time(), 'KEY_GENERATE', 'Generated initial service key: ' . $svcKey['id']);

    saveAllKeys();
}

// 3. Start the main loop
mainLoop();

// End of file. This script will normally never reach here in indefinite mode.
exit(0);
