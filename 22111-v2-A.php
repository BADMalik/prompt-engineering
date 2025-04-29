<?php

/**
 * A standalone real-time memory monitoring and garbage collection (GC) optimizer for long-running PHP CLI applications.
 *
 * Features:
 * 1. Continuously monitor real-time memory usage using memory_get_usage() and memory_get_peak_usage().
 * 2. Detect/Simulate CPU load with sleep() to mimic busy or idle periods.
 * 3. Maintain a time-series buffer (last N samples) to observe memory trends.
 * 4. Dynamically adjust the frequency of garbage collection cycles based on memory usage trends and CPU load.
 * 5. Log garbage collection decisions and memory snapshots to a text file.
 * 6. Dry-run mode to simulate GC actions without actually invoking gc_* functions.
 *
 * Constraints:
 * - Runs as standalone CLI PHP script (no external dependencies).
 * - Must not exceed 20MB memory usage for its own operations.
 * - Sampling interval is configurable via command-line arguments (e.g., 100ms, 500ms).
 * - If memory usage exceeds 70% of PHP memory_limit, GC triggers immediately.
 * - Time-series buffer must not exceed 100 entries (circular array).
 * - All log messages go to gc_log.txt with timestamps in Y-m-d H:i:s format.
 * - Graceful handling of manual termination (Ctrl+C), logging a final summary before exit.
 *
 * Usage:
 * php memory_manager.php --interval=200 --dry-run
 * php memory_manager.php --interval=500
 * (interval in milliseconds; default is 500, dry-run is optional)
 */

// Ensure we are in CLI mode.
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// -----------------------------
// Configuration & CLI Arguments
// -----------------------------
$defaultIntervalMs = 500; // Default 500ms sampling interval
$intervalMs = $defaultIntervalMs;
$isDryRun = false;

// Parse command-line arguments minimally (native methods only).
foreach ($argv as $arg) {
    if (preg_match('/^--interval=(\d+)$/', $arg, $matches)) {
        $intervalMs = (int)$matches[1];
    }
    if ($arg === '--dry-run') {
        $isDryRun = true;
    }
}

// Enforce a minimum interval of 50ms to protect CPU usage.
if ($intervalMs < 50) {
    $intervalMs = 50;
}

// -----------------------------
// Log File Configuration
// -----------------------------
$logFile = 'gc_log.txt';
$startTime = date('Y-m-d H:i:s');
$pid = getmypid();

// -----------------------------
// Memory Limit Utilities
// -----------------------------

/**
 * Convert a PHP-style memory limit (e.g. "128M" , "1G" , "512K" ) into bytes.
 *
 * @param string $val
 * @return int
 */
function parseMemoryLimit($val)
{
    // If it's purely numeric, assume bytes.
    if (is_numeric($val)) {
        return (int)$val;
    }

    $val = trim($val);
    $last = strtolower(substr($val, -1));
    $num = (int)substr($val, 0, -1);
    switch ($last) {
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
            break;
    }
    return $num;
}

$phpMemoryLimitStr = ini_get('memory_limit');
$phpMemoryLimitBytes = parseMemoryLimit($phpMemoryLimitStr);

// If memory_limit is -1 (no limit), set an arbitrary large limit to avoid division by zero.
if ($phpMemoryLimitBytes <= 0) {
    $phpMemoryLimitBytes = 8 * 1024 * 1024 * 1024; // 8GB fallback
}

// Threshold for forced GC (70% of memory_limit).
$gcEmergencyThresholdBytes = (int)($phpMemoryLimitBytes * 0.70);

// -----------------------------
// Time-Series Buffer (Circular)
// -----------------------------
$maxBufferSize = 100;
$memoryBuffer = array_fill(0, $maxBufferSize, 0); // store memory usage samples
$cpuBuffer = array_fill(0, $maxBufferSize, 0); // store simulated CPU load or sleep intervals
$bufferIndex = 0;

/**
 * Insert memory usage and CPU load data into circular buffers.
 *
 * @param int $memUsage
 * @param int $cpuLoad
 */
function recordSample($memUsage, $cpuLoad)
{
    global $memoryBuffer, $cpuBuffer, $bufferIndex, $maxBufferSize;

    $memoryBuffer[$bufferIndex] = $memUsage;
    $cpuBuffer[$bufferIndex] = $cpuLoad;

    $bufferIndex = ($bufferIndex + 1) % $maxBufferSize;
}

/**
 * Retrieve average memory usage from the last N samples (N <=$maxBufferSize).
 *
 * @param int $numSamples
 * @return float
 */
function getAverageMemory($numSamples = 10)
{
    global $memoryBuffer, $bufferIndex, $maxBufferSize;

    // If $numSamples is larger than our buffer, limit it
    if ($numSamples > $maxBufferSize) {
        $numSamples = $maxBufferSize;
    }

    // We go backwards from $bufferIndex
    $sum = 0;
    $count = 0;
    $idx = $bufferIndex - 1;
    if ($idx < 0) {
        $idx = $maxBufferSize - 1;
    }

    for ($i = 0; $i < $numSamples; $i++) {
        $sum += $memoryBuffer[$idx];
        $count++;
        $idx--;
        if ($idx < 0) {
            $idx = $maxBufferSize - 1;
        }
    }

    return ($count > 0) ? ($sum / $count) : 0;
}

/**
 * Retrieve average CPU load over the last N samples (this is simulated with sleep values).
 *
 * @param int $numSamples
 * @return float
 */
function getAverageCpuLoad($numSamples = 10)
{
    global $cpuBuffer, $bufferIndex, $maxBufferSize;

    if ($numSamples > $maxBufferSize) {
        $numSamples = $maxBufferSize;
    }

    $sum = 0;
    $count = 0;
    $idx = $bufferIndex - 1;
    if ($idx < 0) {
        $idx = $maxBufferSize - 1;
    }

    for ($i = 0; $i < $numSamples; $i++) {
        $sum += $cpuBuffer[$idx];
        $count++;
        $idx--;
        if ($idx < 0) {
            $idx = $maxBufferSize - 1;
        }
    }

    // In this script, a higher sleep might ironically mean "idle" CPU. We'll invert it for a "cpuLoad" metric.
    // If you wish to treat "sleep time" as "idle time," you might map it differently. Here:
    // We define CPU load in percentages. We'll store a random 0..100 in $cpuBuffer. The smaller the sleep, the busier.
    return ($count > 0) ? ($sum / $count) : 0;
}

// -----------------------------
// GC Control
// -----------------------------
$gcCycleInterval = 10; // We attempt a GC every N samples if not forced
$sampleCount = 0; // Counts how many samples have passed since last GC
$totalGcCalls = 0;
$totalForcedGc = 0;
$isGcEnabled = (!$isDryRun) ? gc_enabled() : false; // track if GC is globally enabled
$scriptPeakMemory = 0; // track script's own peak usage

// If not in dry-run, enable GC to allow programmatic calls.
if (!$isDryRun && !$isGcEnabled) {
    gc_enable();
    $isGcEnabled = true;
}

// -----------------------------
// Logging
// -----------------------------
/**
 * Write an entry to the log file with timestamp.
 *
 * @param string $message
 * @return void
 */
function logMessage($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// Log the start of the program:
logMessage("-------- GC Optimizer Script START (PID $pid) at $startTime --------");
logMessage("Memory limit: " . ini_get('memory_limit') . " (Parsed bytes: $GLOBALS[phpMemoryLimitBytes])");
logMessage("Sampling interval: {$GLOBALS['intervalMs']} ms");
logMessage("Dry run: " . ($isDryRun ? "YES" : "NO"));

// -----------------------------
// Signal Handling (Ctrl+C)
// -----------------------------
declare(ticks=1);

/**
 * Signal handler for graceful exit when user presses Ctrl+C
 */
function signalHandler($signo)
{
    switch ($signo) {
        case SIGINT:
            // Log final summary before exit
            finalizeAndExit();
            break;
    }
}

/**
 * Final summary logging and exit.
 *
 * @return void
 */
function finalizeAndExit()
{
    global $totalGcCalls, $totalForcedGc, $logFile, $scriptPeakMemory;

    $finalMemUsage = memory_get_usage(true);
    $peakAppMemory = memory_get_peak_usage(true);

    $msg = "Final Summary:
            Total GC Invocations: $totalGcCalls
            Forced GC (Emergency) calls: $totalForcedGc
            Script Peak Memory: $scriptPeakMemory bytes (script-limited)
            memory_get_peak_usage(): $peakAppMemory bytes
            Final memory usage: $finalMemUsage bytes";

    logMessage($msg);
    logMessage("-------- GC Optimizer Script EXIT --------");
    exit(0);
}

pcntl_signal(SIGINT, 'signalHandler');

// -----------------------------
// Main Loop
// -----------------------------
while (true) {
    // 1) Check memory usage
    $currentMemory = memory_get_usage(true);
    $peakMemory = memory_get_peak_usage(true);

    // Track script's own peak usage to stay under 20MB if possible
    if ($currentMemory > $scriptPeakMemory) {
        $scriptPeakMemory = $currentMemory;
    }

    // 2) Simulate CPU load. For example, assign a random 0..100 as "cpu load"
    // and sleep accordingly (rough simulation).
    $cpuLoad = mt_rand(0, 100); // 0 -> idle, 100 -> very busy
    // We'll interpret a large cpuLoad as less sleep (more "CPU bound"), small load as more sleep.
    // This is just for demonstration and trend usage.
    $sleepMs = 100 - $cpuLoad; // The busier we claim to be, the less we sleep.

    // In real usage, you might measure CPU load from /proc or ignore CPU load entirely.
    if ($sleepMs < 0) {
        $sleepMs = 0;
    }

    // 3) Record the sample in circular buffers
    recordSample($currentMemory, $cpuLoad);

    // 4) Check if we exceed 70% memory limit -> forced GC
    if ($currentMemory >= $gcEmergencyThresholdBytes) {
        if (!$isDryRun) {
            $collectedCycles = gc_collect_cycles();
            $totalGcCalls++;
            $totalForcedGc++;
            logMessage("Forced GC triggered (mem usage {$currentMemory} bytes >= 70% of limit). Cycles collected: $collectedCycles");
        } else {
            logMessage("[Dry-Run] Forced GC would have triggered (mem usage {$currentMemory} bytes >= 70% of limit).");
        }
        $sampleCount = 0; // reset the counter after forced GC
    } else {
        // 5) Periodic GC logic (dynamic)
        $sampleCount++;
        // Basic dynamic approach: If memory is trending upward and CPU load is high, do GC more often.
        $avgMem = getAverageMemory(10);
        $avgCpu = getAverageCpuLoad(10);

        // Increase GC frequency (reduce interval) if memory is high or CPU load is high
        // Decrease GC frequency (increase interval) if memory is stable or CPU load is low
        // This is a simplistic approach for demonstration:
        if ($avgMem > ($phpMemoryLimitBytes * 0.5) || $avgCpu > 70) {
            // memory usage above 50% or CPU load > 70 -> shorten interval
            $gcCycleInterval = max(3, $gcCycleInterval - 1);
        } else {
            // memory usage below 50% or CPU load < 70 -> lengthen interval
            $gcCycleInterval = min(30, $gcCycleInterval + 1);
        }

        // If it's time to run the GC cycle based on $gcCycleInterval
        if ($sampleCount >= $gcCycleInterval) {
            if (!$isDryRun) {
                $collectedCycles = gc_collect_cycles();
                $totalGcCalls++;
                logMessage("Periodic GC invoked. Interval: $gcCycleInterval, Cycles collected: $collectedCycles");
            } else {
                logMessage("[Dry-Run] Periodic GC would have been invoked. Interval: $gcCycleInterval");
            }
            $sampleCount = 0;
        }
    }

    // 6) Log a brief snapshot each cycle
    logMessage("Memory: $currentMemory bytes, Peak: $peakMemory, GC Interval: $gcCycleInterval, CPU Load: $cpuLoad");

    // Sleep for the sampling interval to avoid spinning
    usleep($intervalMs * 1000);
    // Also simulate CPU usage
    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }

    // Safety check: If the script itself is above 20MB usage, log a warning.
    // (We won't forcibly exit, but we warn.)
    if ($currentMemory > (20 * 1024 * 1024)) {
        logMessage("WARNING: Script memory usage has exceeded 20MB!");
    }
}

/**
 * If the loop ever breaks (it shouldn't normally), we finalize.
 */
finalizeAndExit();
