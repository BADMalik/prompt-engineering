<?php

/**
 * Automated Key Rotation & Auditing Service with Console Logs
 * -----------------------------------------------------------
 * Standalone, Procedural PHP Script for Simulated Key Lifecycle Management
 */

define('KEY_LENGTH', 32);
define('ROTATION_INTERVAL_SECONDS', 10);
define('MAX_LOG_LINES', 500);
define('STORAGE_DIR', __DIR__ . '/storage');
define('KEYS_FILE', STORAGE_DIR . '/keys.json');
define('AUDIT_LOG_FILE', STORAGE_DIR . '/audit.log');

if (!file_exists(STORAGE_DIR)) mkdir(STORAGE_DIR);
if (!file_exists(KEYS_FILE)) file_put_contents(KEYS_FILE, json_encode([]));
if (!file_exists(AUDIT_LOG_FILE)) file_put_contents(AUDIT_LOG_FILE, '');

// Generate a secure 256-bit key
function generate_key()
{
    return bin2hex(random_bytes(KEY_LENGTH));
}

function log_to_terminal($msg)
{
    echo '[' . date('c') . "] $msg\n";
}

function write_audit_log($message)
{
    $timestamp = date('c');
    $prev_hash = get_last_log_hash();
    $entry = "$timestamp | $message | prev_hash:$prev_hash";
    $new_hash = hash('sha256', $entry);
    file_put_contents(AUDIT_LOG_FILE, "$entry | hash:$new_hash\n", FILE_APPEND);
    log_to_terminal("AUDIT: $message");
    rotate_logs_if_needed();
}

function get_last_log_hash()
{
    $lines = file(AUDIT_LOG_FILE, FILE_IGNORE_NEW_LINES);
    if (empty($lines)) return 'GENESIS';
    $last = end($lines);
    preg_match('/hash:(\w{64})$/', $last, $matches);
    return $matches[1] ?? 'GENESIS';
}

function load_keys()
{
    return json_decode(file_get_contents(KEYS_FILE), true);
}

function save_keys($keys)
{
    file_put_contents(KEYS_FILE, json_encode($keys, JSON_PRETTY_PRINT));
}

function rotate_keys()
{
    log_to_terminal("Starting key rotation...");
    $keys = load_keys();
    foreach ($keys as $user => &$data) {
        $old_key = $data['key'];
        $new_key = generate_unique_key();
        $data['key'] = $new_key;
        $data['generated_at'] = date('c');
        $data['expired'] = false;
        write_audit_log("Rotated key for $user (old:$old_key new:$new_key)");
        log_to_terminal("Key rotated for $user");
    }
    save_keys($keys);
    log_to_terminal("Key rotation complete.");
}

function generate_unique_key()
{
    $used = [];
    $keys = load_keys();
    foreach ($keys as $info) $used[] = $info['key'];
    do {
        $new_key = generate_key();
    } while (in_array($new_key, $used));
    return $new_key;
}

function simulate_access($user, $role)
{
    $keys = load_keys();
    if (!isset($keys[$user])) {
        write_audit_log("ACCESS DENIED for $user ($role) - no key");
        log_to_terminal("ACCESS DENIED to $user ($role) - no key found");
        return;
    }
    if ($keys[$user]['expired']) {
        write_audit_log("ACCESS DENIED for $user ($role) - expired key");
        log_to_terminal("ACCESS DENIED to $user ($role) - expired key");
        return;
    }
    write_audit_log("ACCESS GRANTED to $user ($role) - key: {$keys[$user]['key']}");
    log_to_terminal("ACCESS GRANTED to $user ($role)");
}

function expire_key($user)
{
    $keys = load_keys();
    if (isset($keys[$user])) {
        $keys[$user]['expired'] = true;
        write_audit_log("Manually expired key for $user");
        save_keys($keys);
        log_to_terminal("Key manually expired for $user");
    }
}

function create_user($user, $role)
{
    $keys = load_keys();
    if (!isset($keys[$user])) {
        $keys[$user] = [
            'key' => generate_unique_key(),
            'role' => $role,
            'generated_at' => date('c'),
            'expired' => false
        ];
        write_audit_log("Created key for new $role: $user");
        save_keys($keys);
        log_to_terminal("Created new $role user: $user");
    }
}

function rotate_logs_if_needed()
{
    $lines = file(AUDIT_LOG_FILE);
    if (count($lines) >= MAX_LOG_LINES) {
        $archive = AUDIT_LOG_FILE . '.' . time();
        rename(AUDIT_LOG_FILE, $archive);
        file_put_contents(AUDIT_LOG_FILE, '');
        log_to_terminal("Audit log rotated: archived to " . basename($archive));
    }
}

// ==== SERVICE LOOP ====

create_user('alice', 'admin');
create_user('service1', 'service');
create_user('service2', 'service');

log_to_terminal("Starting Key Rotation & Audit Service...");
while (true) {
    simulate_access('alice', 'admin');
    simulate_access('service1', 'service');
    expire_key('service2'); // simulate revocation
    simulate_access('service2', 'service');
    rotate_keys();
    log_to_terminal("Sleeping for " . ROTATION_INTERVAL_SECONDS . " seconds...\n");
    sleep(ROTATION_INTERVAL_SECONDS);
}
