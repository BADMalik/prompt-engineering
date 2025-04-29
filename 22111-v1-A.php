<?php

/**
 * Real-time Memory Monitoring & Garbage Collection Optimizer
 *
 * A standalone PHP CLI script that continuously monitors memory usage,
 * simulates application load, and dynamically adjusts PHP's garbage
 * collection behavior. Includes a dry-run mode for testing without
 * performing actual GC operations.
 *
 * Usage (examples):
 *   php gc_optimizer.php --interval=500 --dry-run
 *   php gc_optimizer.php --interval=100
 *
 * Command-line options:
 *   --interval=N   Sampling interval in milliseconds (default 500).
 *   --dry-run      Simulate GC decisions without invoking GC operations.
 *
 * Constraints and features:
 *   - Continuously monitors memory using memory_get_usage() and memory_get_peak_usage().
 *   - Simulates CPU load using random sleep intervals.
 *   - Maintains a time-series buffer (circular array) of memory usage samples.
 *   - Dynamically adjusts the frequency of GC cycles based on memory trends.
 *   - Logs GC events and memory usage snapshots to gc_log.txt.
 *   - Dry-run mode available; does not actually trigger GC.
 *   - Does not exceed 20MB usage (for this script alone) and uses only native PHP functions.
 *   - Triggers GC if memory usage exceeds 70% of PHP's memory_limit, regardless of interval.
 *   - Cleans up gracefully on termination (Ctrl+C) and logs final summary.
 *
 * Note:
 *   This script is illustrative and may need further refinement for production use.
 *   It uses simplistic trend detection and CPU load simulation to demonstrate the concept.
 */

// --------------------------------------------------------------
// 1. INITIAL SETUP & CONFIG
// --------------------------------------------------------------

// Ensure we're running in CLI mode.
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script must be run from the command line.\n");
    exit(1);
}

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'stderr');

// Default configuration
$defaultIntervalMs = 500;  // 500ms sampling interval
$maxBufferSize     = 100;  // circular buffer for memory stats
$immediateGCUsageThresholdPercent = 70.0; // triggers GC if usage > 70% of mem_limit
$logFile           = __DIR__ . DIRECTORY_SEPARATOR . "gc_log.txt";

// Dynamic/Adaptive GC interval (in seconds), used as a guideline
$minGCIntervalSec  = 5;
$maxGCIntervalSec  = 60;
$initialGCIntervalSec = 15; // start with 15 seconds, adapt as we go

// Parse CLI arguments
$intervalMs = $defaultIntervalMs;
$dryRun     = false;
foreach ($argv as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $matches)) {
        $intervalMs = (int)$matches[1];
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

$intervalUs = $intervalMs * 1000; // convert ms to microseconds

// --------------------------------------------------------------
// 2. MEMORY LIMIT PARSING
// --------------------------------------------------------------
function parseMemoryLimit($limitString)
{
    // Convert shorthand memory notation (e.g., "512M", "1G") to bytes
    // and return an integer.
    $limitString = trim($limitString);
    if ($limitString === '-1') {
        // -1 means "no limit"
        return PHP_INT_MAX;
    }

    $units = strtoupper(substr($limitString, -1));
    $value = (int)$limitString;
    switch ($units) {
        case 'G':
            $value *= 1024 * 1024 * 1024;
            break;
        case 'M':
            $value *= 1024 * 1024;
            break;
        case 'K':
            $value *= 1024;
            break;
        default:
            // if no unit, treat as bytes
            break;
    }
    return $value;
}

$memoryLimitBytes = parseMemoryLimit(ini_get('memory_limit'));
$immediateGCLimit = $memoryLimitBytes * ($immediateGCUsageThresholdPercent / 100.0);

// --------------------------------------------------------------
// 3. LOGGING
// --------------------------------------------------------------

/**
 * Append timestamped log messages to the log file.
 */
function logMessage($message, $logFile)
{
    $ts    = date('Y-m-d H:i:s');
    $entry = sprintf("[%s] %s\n", $ts, $message);
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// --------------------------------------------------------------
// 4. SIGNAL & SHUTDOWN HANDLING
// --------------------------------------------------------------

$shouldShutdown = false;
$gcOperations   = 0; // how many times we triggered GC
$memorySamples  = []; // to record last N memory usage values
$sampleIndex    = 0;  // circular index
$totalSamples   = 0;  // count total number of samples
$peakUsage      = 0;  // track our own peak usage
$lastGCTime     = microtime(true);
$currentGCIntervalSec = $initialGCIntervalSec;

// We will store final stats for summary
$maxObservedUsage = 0;
$maxObservedUsagePeak = 0;

/**
 * Handle manual termination (Ctrl+C) or kill signals gracefully.
 */
function handleSignal($signo)
{
    global $shouldShutdown;
    $shouldShutdown = true;
}

/**
 * Called upon script termination; logs a final summary.
 */
function shutdownFunction()
{
    global $shouldShutdown, $gcOperations, $logFile, $memorySamples, $totalSamples;
    global $maxObservedUsage, $maxObservedUsagePeak;

    // If the script ends normally or via Ctrl+C, log final summary.
    $finalStats = sprintf(
        "Shutting down. Total samples: %d, GC operations: %d, Max Mem Usage: %s bytes, Max Peak Mem Usage: %s bytes",
        $totalSamples,
        $gcOperations,
        number_format($maxObservedUsage),
        number_format($maxObservedUsagePeak)
    );
    logMessage($finalStats, $logFile);
}

// Register shutdown function
register_shutdown_function('shutdownFunction');

// If possible, install signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, 'handleSignal');
    pcntl_signal(SIGTERM, 'handleSignal');
    pcntl_async_signals(true);
}

// --------------------------------------------------------------
// 5. HELPER: GARBAGE COLLECTION WRAPPERS
// --------------------------------------------------------------

/**
 * Invoke garbage collection cycle (simulated if dry-run)
 */
function triggerGC($dryRun, &$gcOperations, $logFile)
{
    if (!$dryRun) {
        gc_enable();
        $collected = gc_collect_cycles();
        gc_disable();
        logMessage("GC triggered, collected: $collected cycles", $logFile);
    } else {
        logMessage("GC (dry-run): simulation only, no cleanup performed", $logFile);
    }
    $gcOperations++;
}

// --------------------------------------------------------------
// 6. MAIN LOOP
// --------------------------------------------------------------

logMessage("Starting GC Optimizer. Interval: {$intervalMs}ms, Dry-run: " . ($dryRun ? 'true' : 'false'), $logFile);

// Minimal initialization
if (!$dryRun) {
    // Begin with GC enabled - then we will turn it on/off as needed
    gc_enable();
} else {
    gc_disable();
}

// Prepare a circular buffer for storing memory usage/time
$memorySamples = array_fill(0, $maxBufferSize, 0);

// Main loop
while (!$shouldShutdown) {
    // -----------------------------------------------------------------
    // 6.1 Sample current memory usage
    // -----------------------------------------------------------------
    $currentUsage = memory_get_usage();
    $currentPeak  = memory_get_peak_usage();

    $maxObservedUsage     = max($maxObservedUsage, $currentUsage);
    $maxObservedUsagePeak = max($maxObservedUsagePeak, $currentPeak);

    // Store sample in circular buffer
    $memorySamples[$sampleIndex] = $currentUsage;
    $sampleIndex = ($sampleIndex + 1) % $maxBufferSize;
    $totalSamples++;

    // -----------------------------------------------------------------
    // 6.2 Check immediate GC trigger condition (70% memory limit)
    // -----------------------------------------------------------------
    if ($currentUsage > $immediateGCLimit) {
        logMessage("Memory usage exceeded {$immediateGCUsageThresholdPercent}%, forcing immediate GC.", $logFile);
        triggerGC($dryRun, $gcOperations, $logFile);
        $lastGCTime = microtime(true);
        // We can optionally reset adaptive interval here, or set to minimum
        $currentGCIntervalSec = $minGCIntervalSec;
    }

    // -----------------------------------------------------------------
    // 6.3 Basic memory usage trend detection (adaptive interval)
    // -----------------------------------------------------------------
    // Simple approach: average last 10 samples and see if usage is rising.
    // This is just illustrative, could be replaced with a more refined approach.
    $numTrendSamples = min($totalSamples, 10);
    $sum = 0;
    for ($i = 1; $i <= $numTrendSamples; $i++) {
        // look back i samples in a circular manner
        $idx = ($sampleIndex - $i + $maxBufferSize) % $maxBufferSize;
        $sum += $memorySamples[$idx];
    }
    $avgUsage  = $numTrendSamples > 0 ? $sum / $numTrendSamples : $currentUsage;

    // If average usage is significantly below current usage => usage is rising
    // Adjust GC interval shorter if usage is significantly higher than average
    // Note: This is a naive indicator. Production code would refine logic here.
    if ($currentUsage > 1.2 * $avgUsage) {
        // memory is trending up
        $currentGCIntervalSec = max($minGCIntervalSec, $currentGCIntervalSec - 5);
    } elseif ($currentUsage < 0.8 * $avgUsage) {
        // memory is trending down
        $currentGCIntervalSec = min($maxGCIntervalSec, $currentGCIntervalSec + 5);
    }

    // -----------------------------------------------------------------
    // 6.4 Maybe trigger GC based on adaptive interval
    // -----------------------------------------------------------------
    $timeSinceLastGC = microtime(true) - $lastGCTime;
    if ($timeSinceLastGC >= $currentGCIntervalSec) {
        logMessage("Time-based GC condition triggered. Interval: {$currentGCIntervalSec}s", $logFile);
        triggerGC($dryRun, $gcOperations, $logFile);
        $lastGCTime = microtime(true);
    }

    // -----------------------------------------------------------------
    // 6.5 Simulate CPU load or idle - random sleep
    // -----------------------------------------------------------------
    // We'll randomly choose a small sleep to mimic CPU-bound tasks or idle times
    // For example, 50% chance to sleep 0-2 * sampling interval, 50% chance no sleep
    // (purely to illustrate "variable workload" effect on intervals).
    if (mt_rand(0, 1) === 1) {
        usleep(mt_rand(0, 2 * $intervalUs));
    }

    // -----------------------------------------------------------------
    // 6.6 Sleep for the sampling interval
    // -----------------------------------------------------------------
    usleep($intervalUs);

    // If we can catch signals, do so
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

// End of main loop. The shutdownFunction() will fire automatically upon exit.
exit(0);
