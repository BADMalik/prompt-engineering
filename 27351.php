<?php

// Initialize global memory tracking data
$memoryUsageHistory = [];  // Stores historical memory data
$memoryZones = ['Low', 'Medium', 'High'];  // Memory zones for GC adjustment
$gcThresholds = ['Low' => 50, 'Medium' => 75, 'High' => 90];  // GC thresholds based on memory zones
$criticalMemoryThreshold = 95;  // Critical memory usage (percent)
$logFile = 'memory_gc_log.txt';  // Log file for memory usage and GC actions

// Function to log messages to a file and print to terminal
function logMessage($message)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";

    // Write to log file
    file_put_contents($logFile, $logEntry . "\n", FILE_APPEND);

    // Print to terminal
    echo $logEntry . "\n";
}

// Function to get current memory usage as a percentage
function getMemoryUsagePercentage()
{
    $memoryUsage = memory_get_usage(true);  // Get current memory usage in bytes
    $memoryLimit = ini_get('memory_limit');  // Get PHP memory limit (as a string, e.g., "128M")
    $memoryLimitBytes = convertToBytes($memoryLimit);
    return ($memoryUsage / $memoryLimitBytes) * 100;
}

// Convert human-readable memory limit (e.g., "128M") to bytes
function convertToBytes($value)
{
    $unit = strtolower(substr($value, -1));
    $value = (int)$value;
    switch ($unit) {
        case 'k':
            return $value * 1024;
        case 'm':
            return $value * 1048576;
        case 'g':
            return $value * 1073741824;
        default:
            return $value;
    }
}

// Function to categorize current memory usage into zones (Low, Medium, High)
function getMemoryZone($memoryUsagePercentage)
{
    global $gcThresholds;
    if ($memoryUsagePercentage <= $gcThresholds['Low']) {
        return 'Low';
    } elseif ($memoryUsagePercentage <= $gcThresholds['Medium']) {
        return 'Medium';
    } else {
        return 'High';
    }
}

// Function to update historical memory usage data (keeping last 10 minutes of data)
function updateMemoryUsageHistory($memoryUsagePercentage)
{
    global $memoryUsageHistory;
    // Keep last 10 data points (representing last 10 minutes)
    if (count($memoryUsageHistory) >= 10) {
        array_shift($memoryUsageHistory);  // Remove oldest entry
    }
    // Store new memory usage percentage
    $memoryUsageHistory[] = $memoryUsagePercentage;
}

// Function to predict memory usage trend based on historical data (simple average prediction)
function predictMemoryUsageTrend()
{
    global $memoryUsageHistory;
    if (count($memoryUsageHistory) < 2) {
        return 0;  // Not enough data to predict, return no change
    }

    $averageTrend = 0;
    for ($i = 1; $i < count($memoryUsageHistory); $i++) {
        $averageTrend += $memoryUsageHistory[$i] - $memoryUsageHistory[$i - 1];  // Calculate differences between data points
    }
    return $averageTrend / (count($memoryUsageHistory) - 1);  // Return the average trend
}

// Function to adjust the GC threshold based on memory usage and trends
function adjustGCThreshold($currentMemoryZone, $predictedTrend)
{
    global $gcThresholds;

    // If memory usage is trending upwards, we may need to lower the GC threshold to trigger more frequent GC
    if ($predictedTrend > 0) {
        if ($currentMemoryZone == 'Low' && $gcThresholds['Low'] > 40) {
            $gcThresholds['Low'] -= 5;  // Lower threshold slightly for proactive GC
            logMessage("Adjusted Low zone GC threshold to " . $gcThresholds['Low']);
        } elseif ($currentMemoryZone == 'Medium' && $gcThresholds['Medium'] > 60) {
            $gcThresholds['Medium'] -= 5;  // Lower threshold for Medium zone
            logMessage("Adjusted Medium zone GC threshold to " . $gcThresholds['Medium']);
        } elseif ($currentMemoryZone == 'High' && $gcThresholds['High'] > 80) {
            $gcThresholds['High'] -= 5;  // Lower threshold for High zone
            logMessage("Adjusted High zone GC threshold to " . $gcThresholds['High']);
        }
    }
    // If memory usage is trending downwards, we may relax the GC threshold
    elseif ($predictedTrend < 0) {
        if ($currentMemoryZone == 'Low' && $gcThresholds['Low'] < 60) {
            $gcThresholds['Low'] += 5;  // Increase threshold slightly to prevent over-aggressive GC
            logMessage("Adjusted Low zone GC threshold to " . $gcThresholds['Low']);
        } elseif ($currentMemoryZone == 'Medium' && $gcThresholds['Medium'] < 85) {
            $gcThresholds['Medium'] += 5;
            logMessage("Adjusted Medium zone GC threshold to " . $gcThresholds['Medium']);
        } elseif ($currentMemoryZone == 'High' && $gcThresholds['High'] < 95) {
            $gcThresholds['High'] += 5;
            logMessage("Adjusted High zone GC threshold to " . $gcThresholds['High']);
        }
    }
}

// Function to handle memory pressure situations and force GC
function handleMemoryPressure($memoryUsagePercentage)
{
    global $criticalMemoryThreshold;

    if ($memoryUsagePercentage >= $criticalMemoryThreshold) {
        // If memory usage exceeds critical threshold, force GC
        logMessage("Critical memory usage reached ($memoryUsagePercentage%). Forcing GC...");
        gc_collect_cycles();  // Force garbage collection
    }
}

// Main execution loop
$counter = 0;  // Counter to show progress
while (true) {
    $counter++;

    // Log progress in the terminal
    logMessage("=== Execution cycle $counter ===");

    $currentMemoryUsagePercentage = getMemoryUsagePercentage();
    $currentMemoryZone = getMemoryZone($currentMemoryUsagePercentage);

    // Log memory usage and zone
    logMessage("Current memory usage: $currentMemoryUsagePercentage% ($currentMemoryZone zone)");

    // Update memory history with current usage
    updateMemoryUsageHistory($currentMemoryUsagePercentage);

    // Predict memory usage trend
    $predictedTrend = predictMemoryUsageTrend();

    // Log memory trend prediction
    logMessage("Predicted memory usage trend: " . ($predictedTrend > 0 ? "Upward" : ($predictedTrend < 0 ? "Downward" : "Stable")));

    // Adjust the GC threshold based on the current zone and predicted trend
    adjustGCThreshold($currentMemoryZone, $predictedTrend);

    // Handle critical memory pressure
    handleMemoryPressure($currentMemoryUsagePercentage);

    // Log the status after each loop
    logMessage("Memory threshold status: Low = {$gcThresholds['Low']}, Medium = {$gcThresholds['Medium']}, High = {$gcThresholds['High']}");

    // Simulate a sleep for one minute to periodically check memory usage (this loop can be adjusted as per requirements)
    sleep(5);
}
