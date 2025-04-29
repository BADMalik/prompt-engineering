<?php
// Define constants
define('MEMORY_LIMIT_THRESHOLD', 0.7);
define('BUFFER_SIZE', 100);
define('LOG_FILE', 'gc_log.txt');

// Command-line arguments
$options = getopt("", ["interval:", "dry-run"]);
$interval = isset($options['interval']) ? (int)$options['interval'] : 500; // Default interval is 500ms
$dryRun = isset($options['dry-run']);

// Ensure interval is at least 100ms
$interval = max($interval, 100);

// Enable garbage collection
gc_enable();

// Initialize memory trend buffer
$memoryBuffer = array_fill(0, BUFFER_SIZE, 0);
$bufferIndex = 0;

// Function to log messages
function logMessage($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "$timestamp - $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

// Function to handle termination
function handleTermination()
{
    $peakMemory = memory_get_peak_usage(true);
    $currentMemory = memory_get_usage(true);
    logMessage("Termination detected. Peak memory: $peakMemory bytes, Current memory: $currentMemory bytes");
    exit(0);
}

// Register signal handling for termination
pcntl_signal(SIGINT, function () {
    handleTermination();
});

// Main monitoring loop
while (true) {
    $currentMemory = memory_get_usage(true);
    $peakMemory = memory_get_peak_usage(true);
    $memoryLimit = (int) ini_get('memory_limit') * 1024 * 1024; // Convert memory limit to bytes

    // Update memory buffer
    $memoryBuffer[$bufferIndex] = $currentMemory;
    $bufferIndex = ($bufferIndex + 1) % BUFFER_SIZE;

    // Check if memory usage exceeds threshold
    if ($currentMemory > MEMORY_LIMIT_THRESHOLD * $memoryLimit) {
        if (!$dryRun) {
            gc_collect_cycles();
            logMessage("GC triggered due to memory threshold exceeded. Collected cycles: " . gc_collect_cycles());
        } else {
            logMessage("[DRY-RUN] GC would be triggered due to memory threshold exceeded.");
        }
    }

    // Log memory snapshot
    logMessage("Memory snapshot: Current: $currentMemory bytes, Peak: $peakMemory bytes");

    // Simulate CPU load with sleep
    usleep($interval * 1000);
}
