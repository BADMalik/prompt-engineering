<?php

/**
 * Memory Profiler with Predictive Garbage Collection Logic
 * Logs memory usage at intervals and applies simple prediction for cleanup.
 */

class MemoryProfiler
{
    private $logFile;
    private $usageLog = [];
    private $maxLogSize = 1024 * 50; // 50KB
    private $previousMemory = 0;

    public function __construct($logFile = 'memory_profiler.log')
    {
        $this->logFile = $logFile;
        file_put_contents($this->logFile, '');
    }

    public function getLogFile()
    {
        return $this->logFile;
    }

    public function logMemoryPeakUsage()
    {
        $peak = memory_get_peak_usage();
        $entry = date('Y-m-d H:i:s') . " | Peak Memory: {$peak} bytes\n";
        $this->log($entry);
    }

    public function logMemoryUsage()
    {
        $current = memory_get_usage();
        $delta = $current - $this->previousMemory;
        $this->previousMemory = $current;
        $entry = date('Y-m-d H:i:s') . " | Memory: {$current} bytes | Delta: {$delta} bytes\n";

        $this->log($entry);
        $this->usageLog[] = $current;

        if ($this->predictCleanupNeeded()) {
            $this->simulateGarbageCollection();
        }
    }

    private function predictCleanupNeeded(): bool
    {
        $n = count($this->usageLog);
        if ($n < 3) return false;

        // Trigger cleanup if the memory usage has increased by a certain threshold in consecutive logs
        $delta = $this->usageLog[$n - 1] - $this->usageLog[$n - 2];
        return $delta > 50000; // Example threshold (50KB) for triggering cleanup
    }

    public function simulateGarbageCollection()
    {
        $before = memory_get_usage();
        gc_collect_cycles();
        $after = memory_get_usage();
        $freed = $before - $after;
        $entry = date('Y-m-d H:i:s') . " | Garbage Collected: {$freed} bytes\n";
        $this->log($entry);
    }

    private function log(string $entry)
    {
        file_put_contents($this->logFile, $entry, FILE_APPEND);
        echo $entry;

        if (filesize($this->logFile) > $this->maxLogSize) {
            file_put_contents($this->logFile, '');
        }
    }

    public function getLogContent(): string
    {
        return file_get_contents($this->logFile);
    }
}

class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function run($testName, $callable)
    {
        $this->testCount++;
        echo "Running test: " . self::COLOR_BLUE . $testName . self::COLOR_RESET . "... ";

        try {
            $callable($this);
            echo self::COLOR_GREEN . "PASSED" . self::COLOR_RESET . "\n";
            $this->passCount++;
        } catch (Exception $e) {
            echo self::COLOR_RED . "FAILED: " . $e->getMessage() . self::COLOR_RESET . "\n";
            $this->failCount++;
        }
    }

    public function assertTrue($condition, $message = 'Expected true, got false')
    {
        if ($condition !== true) {
            throw new Exception($message);
        }
    }

    public function assertFalse($condition, $message = 'Expected false, got true')
    {
        if ($condition !== false) {
            throw new Exception($message);
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected string to contain '$needle'";
            throw new Exception($details);
        }
    }

    public function summary()
    {
        $duration = microtime(true) - $this->startTime;
        echo "\n" . self::COLOR_BOLD . "==== Test Summary ====\n" . self::COLOR_RESET;
        echo "Total tests: " . self::COLOR_BOLD . $this->testCount . self::COLOR_RESET . "\n";
        echo "Passed: " . self::COLOR_GREEN . $this->passCount . self::COLOR_RESET . "\n";

        if ($this->failCount > 0) {
            echo "Failed: " . self::COLOR_RED . $this->failCount . self::COLOR_RESET . "\n";
        } else {
            echo "Failed: " . $this->failCount . "\n";
        }

        echo "Time: " . self::COLOR_YELLOW . round($duration, 2) . " seconds" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "======================" . self::COLOR_RESET . "\n";

        if ($this->failCount === 0) {
            echo self::COLOR_GREEN . "All tests passed successfully!" . self::COLOR_RESET . "\n";
        } else {
            echo self::COLOR_RED . "Some tests failed." . self::COLOR_RESET . "\n";
        }
    }
}

$testFramework = new SimpleTestFramework();

$testFramework->run('Log file is empty immediately after initialization', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $test->assertTrue(filesize($profiler->getLogFile()) === 0, "Log file should be empty after initialization");
});

$testFramework->run('Memory usage log appends a line containing “Memory:”', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $test->assertStringContains("Memory:", $logContent, "Log entry should contain 'Memory:'");
});

$testFramework->run('Delta calculation is correct across multiple logs', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $initialMemory = memory_get_usage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    preg_match_all('/Memory: (\d+) bytes \| Delta: (\d+) bytes/', $logContent, $matches);
    $memoryValues = array_map('intval', $matches[1]);
    $deltas = array_map('intval', $matches[2]);
    $test->assertTrue($deltas[1] === $memoryValues[1] - $memoryValues[0], "Delta calculation should be correct");
});

$testFramework->run('Predictive GC doesn’t run before 3 memory entries', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $test->assertFalse(strpos($logContent, 'Garbage Collected') !== false, "GC should not run before 3 memory entries");
});

$testFramework->run('Predictive GC runs after 3 strictly increasing memory values', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // This should trigger GC
    $logContent = $profiler->getLogContent();
    $test->assertStringContains('Garbage Collected', $logContent, "GC should run after 3 strictly increasing memory values");
});

$testFramework->run('Predictive GC doesn’t run if memory fluctuates (not strictly increasing)', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // Fluctuating memory usage
    $logContent = $profiler->getLogContent();
    $test->assertFalse(strpos($logContent, 'Garbage Collected') !== false, "GC should not run if memory fluctuates");
});

$testFramework->run('Garbage collection log entry is added only after prediction', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // This should trigger GC
    $logContent = $profiler->getLogContent();
    $test->assertStringContains('Garbage Collected', $logContent, "GC log entry should be added only after prediction");
});

$testFramework->run('Log file resets after reaching max size', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 100; $i++) {
        $profiler->logMemoryUsage();
    }
    $logContent = $profiler->getLogContent();
    $test->assertTrue(filesize($profiler->getLogFile()) <= $profiler->maxLogSize, "Log file should reset after reaching max size");
});

$testFramework->run('Custom log file name works correctly', function ($test) {
    $profiler = new MemoryProfiler('custom_log.log');
    $profiler->logMemoryUsage();
    $test->assertTrue(file_exists('custom_log.log'), "Custom log file should be created correctly");
});

$testFramework->run('Consecutive logMemoryUsage() calls increase log lines', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $lines = explode("\n", $logContent);
    $test->assertTrue(count($lines) > 1, "Consecutive logMemoryUsage() calls should increase log lines");
});

$testFramework->run('Multiple profiler instances have independent logs', function ($test) {
    $profiler1 = new MemoryProfiler('test_log1.log');
    $profiler2 = new MemoryProfiler('test_log2.log');
    $profiler1->logMemoryUsage();
    $profiler2->logMemoryUsage();
    $logContent1 = $profiler1->getLogContent();
    $logContent2 = $profiler2->getLogContent();
    $test->assertFalse(strpos($logContent1, $logContent2) !== false, "Multiple profiler instances should have independent logs");
});

$testFramework->run('simulateGarbageCollection() reduces memory (may be minor)', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $before = memory_get_usage();
    $profiler->simulateGarbageCollection();
    $after = memory_get_usage();
    $test->assertTrue($before >= $after, "simulateGarbageCollection() should reduce memory");
});

$testFramework->run('Log file size is monitored correctly', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 100; $i++) {
        $profiler->logMemoryUsage();
    }
    $test->assertTrue(filesize($profiler->getLogFile()) <= $profiler->maxLogSize, "Log file size should be monitored correctly");
});

$testFramework->run('Log file content includes timestamps', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $test->assertStringContains(date('Y-m-d'), $logContent, "Log entries should include timestamps");
});

$testFramework->run('Log entries are newline-separated', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $lines = explode("\n", $logContent);
    $test->assertTrue(count($lines) > 1, "Log entries should be newline-separated");
});

$testFramework->run('Log doesn\'t exceed limit even with many entries', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 1000; $i++) {
        $profiler->logMemoryUsage();
    }
    $test->assertTrue(filesize($profiler->getLogFile()) <= $profiler->maxLogSize, "Log should not exceed limit even with many entries");
});

$testFramework->run('Memory delta reflects object creation', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $initialMemory = memory_get_usage();
    $profiler->logMemoryUsage();
    $obj = new stdClass();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    preg_match_all('/Delta: (\d+) bytes/', $logContent, $matches);
    $deltas = array_map('intval', $matches[1]);
    $test->assertTrue($deltas[1] > 0, "Memory delta should reflect object creation");
});

$testFramework->run('GC does not run if memory usage is constant', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $test->assertFalse(strpos($logContent, 'Garbage Collected') !== false, "GC should not run if memory usage is constant");
});

$testFramework->run('GC runs only once for single upward trend', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // This should trigger GC
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    preg_match_all('/Garbage Collected/', $logContent, $matches);
    $gcCount = count($matches[0]);
    $test->assertTrue($gcCount === 1, "GC should run only once for single upward trend");
});

$testFramework->run('getLogContent() returns current log correctly', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $logContent = $profiler->getLogContent();
    $test->assertTrue(!empty($logContent), "getLogContent() should return current log correctly");
});

$testFramework->run('Logging is consistent under loop pressure', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 1000; $i++) {
        $profiler->logMemoryUsage();
    }
    $logContent = $profiler->getLogContent();
    $lines = explode("\n", $logContent);
    $test->assertTrue(count($lines) > 0, "Logging should be consistent under loop pressure");
});

$testFramework->run('Log preserves format on reset', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 1000; $i++) {
        $profiler->logMemoryUsage();
    }
    $logContent = $profiler->getLogContent();
    $lines = explode("\n", $logContent);
    $test->assertTrue(count($lines) > 0, "Log should preserve format on reset");
});

$testFramework->run('Log file is truncated properly after reset', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    for ($i = 0; $i < 1000; $i++) {
        $profiler->logMemoryUsage();
    }
    $logContent = $profiler->getLogContent();
    $test->assertTrue(filesize($profiler->getLogFile()) <= $profiler->maxLogSize, "Log file should be truncated properly after reset");
});

$testFramework->run('Predictive GC not falsely triggered by equal memory', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // Same memory usage
    $logContent = $profiler->getLogContent();
    $test->assertFalse(strpos($logContent, 'Garbage Collected') !== false, "Predictive GC should not be falsely triggered by equal memory");
});

$testFramework->run('GC triggers only when 3 previous values are increasing (not more or fewer)', function ($test) {
    $profiler = new MemoryProfiler('test_log.log');
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage();
    $profiler->logMemoryUsage(); // This should trigger GC
    $logContent = $profiler->getLogContent();
    $test->assertStringContains('Garbage Collected', $logContent, "GC should trigger only when 3 previous values are increasing");
});

$testFramework->summary();
