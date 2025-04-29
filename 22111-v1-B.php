<?php

// Configuration constants
define('MAX_MEMORY_LIMIT_PERCENT', 70);
define('MEMORY_BUFFER_SIZE', 100);
define('LOG_FILE', 'gc_log.txt');
define('DRY_RUN_MODE', isset($argv[2]) && $argv[2] === 'dry-run');

// Get sampling interval from command-line argument
$samplingInterval = isset($argv[1]) ? (int)$argv[1] : 500; // Default to 500ms if not provided
$samplingInterval /= 1000; // Convert to seconds

// Initialize memory buffer and counters
$memoryBuffer = [];
$bufferIndex = 0;
$gcCount = 0;

// Enable garbage collection
gc_enable();

function logMessage($message)
{
    $logLine = date('Y-m-d H:i:s') . " - $message\n";
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND);
}

function handleShutdown()
{
    global $memoryBuffer, $gcCount;

    $finalMemoryUsage = memory_get_usage();
    $finalPeakMemoryUsage = memory_get_peak_usage();

    logMessage("Final memory usage: $finalMemoryUsage bytes");
    logMessage("Final peak memory usage: $finalPeakMemoryUsage bytes");
    logMessage("Total garbage collections triggered: $gcCount");
}

register_shutdown_function('handleShutdown');

logMessage("Memory monitoring and GC optimizer started with sampling interval: $samplingInterval seconds");

while (true) {
    $currentMemoryUsage = memory_get_usage();
    $peakMemoryUsage = memory_get_peak_usage();
    $memoryLimit = (int)ini_get('memory_limit') * 1024 * 1024; // Convert to bytes

    // Update memory buffer with circular logic
    $memoryBuffer[$bufferIndex] = $currentMemoryUsage;
    $bufferIndex = ($bufferIndex + 1) % MEMORY_BUFFER_SIZE;

    // Check if memory usage exceeds threshold
    if ($currentMemoryUsage > ($memoryLimit * MAX_MEMORY_LIMIT_PERCENT / 100)) {
        logMessage("Memory usage exceeded threshold ($currentMemoryUsage > " . ($memoryLimit * MAX_MEMORY_LIMIT_PERCENT / 100) . "). Triggering garbage collection.");
        if (!DRY_RUN_MODE) {
            gc_collect_cycles();
            $gcCount++;
        }
    } else {
        // Analyze memory trend
        $memoryTrend = array_slice($memoryBuffer, max(0, $bufferIndex - MEMORY_BUFFER_SIZE + 10), 10); // Last 10 samples
        $memoryTrend = array_filter($memoryTrend); // Remove null values from circular buffer
        $memoryTrendDiff = end($memoryTrend) - (reset($memoryTrend) ?? end($memoryTrend));

        if ($memoryTrendDiff > 0) {
            logMessage("Memory trend increasing. Considering garbage collection.");
            if (!DRY_RUN_MODE) {
                gc_collect_cycles();
                $gcCount++;
            }
        }
    }

    logMessage("Current memory usage: $currentMemoryUsage bytes, Peak memory usage: $peakMemoryUsage bytes");

    // Simulate application load with sleep
    usleep($samplingInterval * 1000000); // Convert seconds to microseconds

    // Check for manual termination
    if (connection_aborted()) {
        logMessage("Manual termination detected. Exiting...");
        break;
    }
}
