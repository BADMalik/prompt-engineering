<?php

/**
 * Real-time Memory Monitoring and Garbage Collection Optimizer for PHP CLI
 * Monitors memory usage, CPU load, and dynamically adjusts GC behavior
 * Usage: php memory_monitor.php --interval=<ms> [--dry-run]
 */

// Configuration and state
class MemoryMonitor {
    private $interval; // Sampling interval in microseconds
    private $dryRun; // Dry-run mode flag
    private $memoryLimit; // PHP memory limit in bytes
    private $memoryBuffer = []; // Circular buffer for memory trends
    private $bufferSize = 100; // Max buffer entries
    private $gcCycles = 0; // Count of GC cycles triggered
    private $logFile = 'gc_log.txt'; // Log file path
    private $running = true; // Control loop flag

    public function __construct($intervalMs, $dryRun) {
        $this->interval = $intervalMs * 1000; // Convert ms to microseconds
        $this->dryRun = $dryRun;
        $this->memoryLimit = $this->getMemoryLimit();
        $this->log("Memory Monitor started. Interval: {$intervalMs}ms, Dry-run: " . ($dryRun ? 'Yes' : 'No'));
        $this->printToTerminal("Memory Monitor started. Interval: {$intervalMs}ms, Dry-run: " . ($dryRun ? 'Yes' : 'No'));
        $this->registerShutdown();
    }

    // Parse PHP memory_limit to bytes
    private function getMemoryLimit() {
        $limit = ini_get('memory_limit');
        $unit = strtoupper(substr($limit, -1));
        $value = (int)$limit;
        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return $value;
        }
    }

    // Log message with timestamp to file
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    // Print message to terminal with timestamp
    private function printToTerminal($message) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] $message\n";
    }

    // Register signal handlers for graceful shutdown
    private function registerShutdown() {
        declare(ticks = 1);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        register_shutdown_function([$this, 'shutdown']);
    }

    // Handle termination signals
    public function handleShutdown($signal) {
        $this->running = false;
    }

    // Final cleanup and summary
    public function shutdown() {
        $peak = memory_get_peak_usage(true) / 1024 / 1024;
        $final = memory_get_usage(true) / 1024 / 1024;
        $message = "Shutdown. Peak Memory: {$peak} MB, Final Memory: {$final} MB, GC Cycles: {$this->gcCycles}";
        $this->log($message);
        $this->printToTerminal($message);
    }

    // Simulate application load (CPU-bound or idle)
    private function simulateLoad() {
        $loadType = rand(0, 2); // 0: idle, 1: light, 2: heavy
        switch ($loadType) {
            case 0: // Idle
                usleep(50000); // 50ms
                return 0.1;
            case 1: // Light
                $start = microtime(true);
                while (microtime(true) - $start < 0.02) {
                    // Simulate light computation
                    $dummy = sin(rand(1, 100));
                }
                return 0.5;
            case 2: // Heavy
                $start = microtime(true);
                while (microtime(true) - $start < 0.05) {
                    // Simulate heavy computation
                    $dummy = pow(rand(1, 1000), 2);
                }
                return 0.9;
        }
        return 0.1;
    }

    // Calculate memory usage trend
    private function analyzeTrend() {
        if (count($this->memoryBuffer) < 2) {
            return 0;
        }
        $recent = array_slice($this->memoryBuffer, -10); // Last 10 samples
        $diffs = [];
        for ($i = 1; $i < count($recent); $i++) {
            $diffs[] = $recent[$i]['usage'] - $recent[$i -1]['usage'];
        }
        $avgDiff = array_sum($diffs) / count($diffs);
        return $avgDiff; // Positive = increasing, Negative = decreasing
    }

    // Decide whether to trigger GC
    private function shouldTriggerGC($usage, $trend, $load) {
        $usagePercent = ($usage / $this->memoryLimit) * 100;

        // Immediate GC if memory usage exceeds 70%
        if ($usagePercent > 70) {
            $message = "High memory usage detected: {$usagePercent}%";
            $this->log($message);
            $this->printToTerminal($message);
            return true;
        }

        // Trigger GC if memory is increasing rapidly and load is moderate
        if ($trend > 100000 && $load < 0.7) { // 100KB/s increase
            $message = "Rapid memory increase detected: {$trend} bytes/sample";
            $this->log($message);
            $this->printToTerminal($message);
            return true;
        }

        // Periodic GC under normal conditions
        static $counter = 0;
        $counter++;
        if ($counter >= 10 && $load < 0.5) { // Every 10 samples if load is low
            $counter = 0;
            return true;
        }

        return false;
    }

    // Main monitoring loop
    public function run() {
        gc_enable();
        $message = "Garbage collection enabled";
        $this->log($message);
        $this->printToTerminal($message);

        while ($this->running) {
            $startTime = microtime(true);

            // Collect memory stats
            $usage = memory_get_usage(true);
            $peak = memory_get_peak_usage(true);
            $load = $this->simulateLoad();

            // Update memory buffer (circular)
            $this->memoryBuffer[] = [
                'time' => time(),
                'usage' => $usage,
                'peak' => $peak,
                'load' => $load
            ];
            if (count($this->memoryBuffer) > $this->bufferSize) {
                array_shift($this->memoryBuffer);
            }

            // Analyze trends and decide on GC
            $trend = $this->analyzeTrend();
            $usageMb = $usage / 1024 / 1024;
            $peakMb = $peak / 1024 / 1024;
            $trendKb = $trend / 1024;
            $message = sprintf(
                "Memory: %.2f MB, Peak: %.2f MB, Load: %.2f, Trend: %.2f KB/sample",
                $usageMb,
                $peakMb,
                $load,
                $trendKb
            );
            $this->log($message);
            $this->printToTerminal($message);

            if ($this->shouldTriggerGC($usage, $trend, $load)) {
                if (!$this->dryRun) {
                    $cycles = gc_collect_cycles();
                    $this->gcCycles++;
                    $message = "GC triggered. Freed cycles: {$cycles}";
                    $this->log($message);
                    $this->printToTerminal($message);
                } else {
                    $message = "GC would have been triggered (dry-run)";
                    $this->log($message);
                    $this->printToTerminal($message);
                }
            }

            // Control sampling interval
            $elapsed = (microtime(true) - $startTime) * 1000000; // Microseconds
            $sleepTime = max(0, $this->interval - $elapsed);
            usleep((int)$sleepTime);
        }

        gc_disable();
        $message = "Garbage collection disabled";
        $this->log($message);
        $this->printToTerminal($message);
    }
}

// Parse command-line arguments
$options = getopt("", ["interval:", "dry-run"]);
$interval = isset($options['interval']) ? (int)$options['interval'] : 500; // Default 500ms
$dryRun = isset($options['dry-run']);

if ($interval < 10 || $interval > 5000) {
    die("Error: Interval must be between 10 and 5000 milliseconds\n");
}

// Run the monitor
$monitor = new MemoryMonitor($interval, $dryRun);
$monitor->run();

?>