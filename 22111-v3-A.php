<?php

/**
 * gc_manager.php
 *
 * A standalone CLI PHP script that monitors real-time memory usage, simulates
 * CPU load, and dynamically adjusts garbage collection. Can also run in a
 * dry-run mode, logging GC decisions without performing actual GC operations.
 *
 * Usage:
 *   php gc_manager.php [--interval=<ms>] [--dry-run]
 *
 * Features:
 *   - Monitors memory usage (current and peak) in real-time
 *   - Simulates CPU load with sleep() to mimic idle/busy periods
 *   - Maintains a circular time-series buffer of memory usage samples
 *   - Dynamically adjusts GC frequency based on memory trends and load
 *   - Logs decisions and memory snapshots to "gc_log.txt"
 *   - Includes a dry-run mode to simulate actions without actual GC
 *   - If memory usage exceeds 70% of memory_limit, triggers immediate GC
 *   - Gracefully handles termination (Ctrl+C) with a final summary
 *
 * Constraints:
 *   - Script's own memory usage must not exceed 20MB
 *   - Maximum of 100 samples in time-series buffer
 *   - Uses only native PHP functions (no external libraries)
 *   - Sampling interval is configurable via command-line argument
 *   - Log file: gc_log.txt
 *
 * Author: ChatGPT example
 * License: Public Domain / Example
 */

/* ------------------------------------------------------
 * Initial Setup and Argument Parsing
 * ------------------------------------------------------ */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "ERROR: This script must be run from the command line.\n");
    exit(1);
}

// Default configuration
$defaultIntervalMs = 500;   // 500 ms
$dryRun            = false;

// Parse command line arguments
foreach ($argv as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $matches)) {
        $defaultIntervalMs = (int)$matches[1];
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

// Convert interval from ms to microseconds
$sampleIntervalUs = $defaultIntervalMs * 1000;

// Open log file in append mode
$logFilePath = "gc_log.txt";
$logHandle   = fopen($logFilePath, 'ab');
if (!$logHandle) {
    fwrite(STDERR, "ERROR: Could not open log file for writing.\n");
    exit(1);
}

// Utility function to log strings with timestamp
function logMessage($handle, $message)
{
    $timestamp = date("Y-m-d H:i:s");
    fwrite($handle, "[$timestamp] $message\n");
    fflush($handle);
}

logMessage($logHandle, "------------------------------------------------");
logMessage($logHandle, "Starting GC manager (interval={$defaultIntervalMs}ms, dryRun=" . ($dryRun ? 'true' : 'false') . ")");

// Memory limit check
$rawMemoryLimit = ini_get('memory_limit');
$memoryLimitBytes = parseMemoryLimit($rawMemoryLimit);

/**
 * parseMemoryLimit - Convert PHP's memory_limit string to an integer in bytes.
 *
 * @param string $val (e.g. "128M", "1G", "256K", or "-1" meaning unlimited).
 * @return int Number of bytes, or -1 if unlimited.
 */
function parseMemoryLimit($val)
{
    // Special case: -1 means "unlimited"
    if ($val == -1) {
        return -1;
    }
    $val  = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $num  = (int)$val;
    switch ($last) {
        case 'g':
            $num *= 1024;
            // fallthrough
        case 'm':
            $num *= 1024;
            // fallthrough
        case 'k':
            $num *= 1024;
    }
    return $num;
}

/* ------------------------------------------------------
 * Signal Handling (for graceful termination)
 * ------------------------------------------------------ */

$shouldTerminate = false;

/**
 * signalHandler - Catches Ctrl+C (SIGINT) and sets a flag for graceful exit.
 */
function signalHandler($signo)
{
    global $shouldTerminate;
    if ($signo === SIGINT) {
        $shouldTerminate = true;
    }
}

// Try to install signal handler if available on this system
if (extension_loaded('pcntl')) {

    declare(ticks=1);
    pcntl_signal(SIGINT, 'signalHandler');
}

/* ------------------------------------------------------
 * Circular Buffer for Memory Usage Samples
 * ------------------------------------------------------ */

$maxBufferSize      = 100;
$memoryUsageSamples = array_fill(0, $maxBufferSize, null);
$bufferIndex        = 0;
$totalSamples       = 0;

// GC frequency management
$defaultGcFrequency = 10;  // GC every 10 samples by default
$gcFrequency        = $defaultGcFrequency;
$gcCycleCounter     = 0;   // Counts how many samples until next GC

// Keep track of statistics
$gcCalls         = 0;
$forcedGcCalls   = 0;
$gcFrequencyMods = 0;

// Timestamps for start/end
$startTime       = microtime(true);

// Turn ON or OFF GC initially
if (!$dryRun) {
    gc_enable();
}
$gcEnabled = true;

/* ------------------------------------------------------
 * Main Monitoring Loop
 * ------------------------------------------------------ */

logMessage($logHandle, "Entering main loop. Press Ctrl+C to exit gracefully.");

// We will simulate load by occasionally sleeping a variable amount of time.
while (!$shouldTerminate) {
    // Check memory usage
    $currentMemoryUsage = memory_get_usage(true);       // in bytes
    $peakMemoryUsage    = memory_get_peak_usage(true);  // in bytes

    // Compute memory usage ratio (against memory_limit). If memory_limit is -1, ignore check.
    $memoryRatio = ($memoryLimitBytes > 0)
        ? $currentMemoryUsage / $memoryLimitBytes
        : 0.0;

    // Store sample in circular buffer
    $memoryUsageSamples[$bufferIndex] = [
        'time'            => microtime(true),
        'mem_usage'       => $currentMemoryUsage,
        'mem_usage_ratio' => $memoryRatio,
    ];

    $bufferIndex = ($bufferIndex + 1) % $maxBufferSize;
    $totalSamples++;

    // Check if memory usage > 70% of memory limit -> If so, force GC
    if ($memoryLimitBytes > 0 && $memoryRatio > 0.70) {
        logMessage($logHandle, "Memory usage exceeded 70%. Forcing GC.");
        if (!$dryRun) {
            $collectedCycles = gc_collect_cycles();
            $gcCalls++;
            $forcedGcCalls++;
        } else {
            $collectedCycles = 0;
        }
        logMessage($logHandle, "GC triggered (forced), cycles collected=" . $collectedCycles);
        // Reset the gcCycleCounter so we don't do a normal GC again immediately.
        $gcCycleCounter = 0;
    } else {
        // Decrement GC cycle counter
        $gcCycleCounter++;
        if ($gcCycleCounter >= $gcFrequency) {
            // Time to perform a GC cycle
            logMessage($logHandle, "Scheduled GC cycle triggered (gcFrequency={$gcFrequency}).");
            $gcCycleCounter = 0;
            if (!$dryRun) {
                $collectedCycles = gc_collect_cycles();
                $gcCalls++;
            } else {
                $collectedCycles = 0;
            }
            logMessage($logHandle, "GC triggered, cycles collected=" . $collectedCycles);
        }
    }

    // Analyze short-term trend (e.g., last 5 samples) to adjust GC frequency
    // This is a simple heuristic: if usage is rising, reduce the GC frequency threshold;
    // if usage is falling, increase it—bounded between 2 and 20.
    // This is just one example of "dynamic" logic.
    if ($totalSamples > 5) {
        $recentSamples = [];
        for ($i = 1; $i <= 5; $i++) {
            $idx = ($bufferIndex - $i + $maxBufferSize) % $maxBufferSize;
            $recentSamples[] = $memoryUsageSamples[$idx]['mem_usage'];
        }
        $first  = $recentSamples[count($recentSamples) - 1];
        $last   = $recentSamples[0];
        // If memory rose more than 10% from first to last in 5 samples
        if ($last > 1.1 * $first) {
            if ($gcFrequency > 2) {
                $gcFrequency--;
                $gcFrequencyMods++;
                logMessage($logHandle, "Rising memory usage detected. Decreasing GC frequency to {$gcFrequency}.");
            }
        } elseif ($last < 0.9 * $first) {
            if ($gcFrequency < 20) {
                $gcFrequency++;
                $gcFrequencyMods++;
                logMessage($logHandle, "Falling memory usage detected. Increasing GC frequency to {$gcFrequency}.");
            }
        }
    }

    // Log current snapshot
    logMessage($logHandle, sprintf(
        "Memory Snapshot: usage=%.2fMB, peak=%.2fMB, ratio=%.2f%%",
        $currentMemoryUsage / 1048576,
        $peakMemoryUsage / 1048576,
        $memoryRatio * 100
    ));

    // Simulate CPU load or idle
    // Randomly sleep 0..2 * sample interval to mimic varying load
    // In real usage, you'd measure CPU load or use real application logic
    $simulatedDelay = rand(0, 2 * $defaultIntervalMs);
    usleep($simulatedDelay * 1000);

    // Also sleep the configured sampling interval
    usleep($sampleIntervalUs);

    // Check if we caught a signal in the meantime (if pcntl is available)
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}

/* ------------------------------------------------------
 * Final Summary and Cleanup
 * ------------------------------------------------------ */
$endTime = microtime(true);
$totalRunSeconds = $endTime - $startTime;

// Summarize memory usage
$currentMemoryUsage = memory_get_usage(true);
$peakMemoryUsage    = memory_get_peak_usage(true);

$summaryMessage  = "GC Manager Terminated.\n";
$summaryMessage .= "Total run time: " . round($totalRunSeconds, 2) . " seconds\n";
$summaryMessage .= "Total samples: $totalSamples\n";
$summaryMessage .= "GC calls (total): $gcCalls\n";
$summaryMessage .= "GC forced calls: $forcedGcCalls\n";
$summaryMessage .= "GC frequency modifications: $gcFrequencyMods\n";
$summaryMessage .= "Final memory usage: " . round($currentMemoryUsage / 1048576, 2) . " MB\n";
$summaryMessage .= "Peak memory usage: " . round($peakMemoryUsage / 1048576, 2) . " MB\n";

logMessage($logHandle, $summaryMessage);
fclose($logHandle);

echo "Exiting gracefully. See gc_log.txt for details.\n";
exit(0);
