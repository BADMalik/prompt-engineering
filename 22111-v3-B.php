<?php
// Define constants
define('MEMORY_LIMIT_THRESHOLD', 0.7);
define('BUFFER_SIZE', 100);
define('LOG_FILE', 'gc_log.txt');

// Command-line arguments
$options = getopt("", ["interval:", "dry-run"]);
$interval = isset($options['interval']) ? (int)$options['interval'] : 500; // Default interval in milliseconds
$dryRun = isset($options['dry-run']);

// Ensure interval is at least 100ms
$interval = max(100, $interval);

// Enable garbage collection
gc_enable();

// Initialize memory trend buffer
$memoryBuffer = array_fill(0, BUFFER_SIZE, 0);
$bufferIndex = 0;

// Get PHP memory limit in bytes
$memoryLimit = (int)(ini_get('memory_limit') * 1024 * 1024);

function logMessage($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

function handleTermination()
{
    $memoryUsage = memory_get_usage();
    $peakMemoryUsage = memory_get_peak_usage();
    $logMessage = "Termination: Memory Usage: $memoryUsage bytes, Peak Memory Usage: $peakMemoryUsage bytes\n";
    logMessage($logMessage);
}

register_shutdown_function('handleTermination');

logMessage("Memory Monitor started with interval: $interval ms, dry-run: " . ($dryRun ? 'Yes' : 'No'));

while (true) {
    $memoryUsage = memory_get_usage();
    $peakMemoryUsage = memory_get_peak_usage();

    // Update memory buffer
    $memoryBuffer[$bufferIndex] = $memoryUsage;
    $bufferIndex = ($bufferIndex + 1) % BUFFER_SIZE;

    // Check if memory usage exceeds threshold
    if ($memoryUsage > MEMORY_LIMIT_THRESHOLD * $memoryLimit) {
        if (!$dryRun) {
            gc_collect_cycles();
            logMessage("GC triggered due to memory threshold: $memoryUsage bytes");
        } else {
            logMessage("Dry-run: GC would be triggered due to memory threshold: $memoryUsage bytes");
        }
    }

    // Simple trend analysis (average of last N samples)
    $averageMemoryUsage = array_sum($memoryBuffer) / BUFFER_SIZE;

    // Simulate CPU load (using sleep)
    usleep($interval * 1000);

    // Log memory snapshot
    logMessage("Memory Snapshot: Usage: $memoryUsage bytes, Peak: $peakMemoryUsage bytes, Average: $averageMemoryUsage bytes");

    // Check for manual termination
    if (connection_aborted()) {
        break;
    }
}
