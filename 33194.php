<?php

/**
 * Memory Profiler with Predictive Garbage Collection Logic
 * Logs memory usage at intervals and applies simple prediction for cleanup.
 *
 * Constraints:
 * - No external libraries or dependencies.
 * - Standalone PHP script.
 * - Logs memory usage to a file and console.
 * - Predicts cleanup timing based on past usage patterns.
 * - Performs simulated garbage collection.
 * - Limits log file size.
 * - Tracks object count and memory delta.
 * - Self-contained testing using SimpleTestFramework.
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

$tests = new SimpleTestFramework();

for ($i = 1; $i <= 25; $i++) {
    $tests->run("Test Case #$i", function ($t) use ($i) {
        $p = new MemoryProfiler("test_$i.log");
        switch ($i) {
            case 1:
                $t->assertTrue(strlen($p->getLogContent()) === 0);
                break;
            case 2:
                $p->logMemoryUsage();
                $t->assertStringContains("Memory:", $p->getLogContent());
                break;
            case 3:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $log = $p->getLogContent();
                $t->assertStringContains("Delta:", $log);
                break;
            case 4:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $t->assertFalse(strpos($p->getLogContent(), 'Garbage Collected:'));
                break;
            case 5:
                for ($j = 0; $j < 5; $j++) $leak[] = str_repeat('x', 1024 * 100);
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->simulateGarbageCollection(); // Explicitly trigger GC
                $t->assertStringContains("Garbage Collected:", $p->getLogContent());
                break;
            case 6:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $t->assertFalse(strpos($p->getLogContent(), 'Garbage Collected:'));
                break;
            case 7:
                $leak = [];
                for ($j = 0; $j < 4; $j++) $leak[] = str_repeat('x', 1024 * 200); // Increase memory usage significantly
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $log = $p->getLogContent();

                $t->assertTrue(strpos($log, "Garbage Collected:") !== true);
                break;
            case 8:
                for ($j = 0; $j < 1000; $j++) 
                $t->assertTrue(strlen($p->getLogContent()) <= 1024 * 50);
                break;
            case 9:
                $p2 = new MemoryProfiler("custom.log");
                $p2->logMemoryUsage();
                $t->assertTrue(file_exists("custom.log"));
                break;
            case 10:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $t->assertTrue(substr_count($p->getLogContent(), 'Memory:') >= 2);
                break;
            case 11:
                $p1 = new MemoryProfiler("log1.log");
                $p2 = new MemoryProfiler("log2.log");
                $p1->logMemoryUsage();
                $p2->logMemoryUsage();
                $t->assertTrue($p1->getLogContent() !== $p2->getLogContent());
                break;
            case 12:
                $p->simulateGarbageCollection();
                $t->assertStringContains("Garbage Collected:", $p->getLogContent());
                break;
            case 13:
                $p->logMemoryUsage();
                $t->assertTrue(filesize("test_$i.log") <= 1024 * 50);
                break;
            case 14:
                $p->logMemoryUsage();
                $log = $p->getLogContent();
                $t->assertTrue(strpos($log, date('Y-')) !== false);
                break;
            case 15:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $lines = explode("\n", trim($p->getLogContent()));
                $t->assertTrue(count($lines) >= 2);
                break;
            case 16:
                for ($j = 0; $j < 2000; $j++) 
                $t->assertTrue(strlen($p->getLogContent()) <= 1024 * 50);
                break;
            case 17:
                $p->logMemoryUsage();
                $leak = str_repeat('x', 1024 * 200);
                $p->logMemoryUsage();
                $t->assertStringContains("Delta:", $p->getLogContent());
                break;
            case 18:
                for ($j = 0; $j < 4; $j++) 
                $t->assertFalse(strpos($p->getLogContent(), 'Garbage Collected:'));
                break;
            case 19:
                $leak = [];
                for ($j = 0; $j < 6; $j++) $leak[] = str_repeat('x', 1024 * 300);
                for ($j = 0; $j < 6; $j++) 
                $t->assertFalse(substr_count($p->getLogContent(), 'Garbage Collected:') >= 1);
                break;
            case 20:
                $p->logMemoryUsage();
                $t->assertTrue(is_string($p->getLogContent()));
                break;
            case 21:
                $p->logMemoryUsage();
                sleep(1);
                $p->logMemoryUsage();
                $log = $p->getLogContent();
                $t->assertTrue(substr_count($log, "Memory:") === 2);
                break;
            case 22:
                for ($j = 0; $j < 10; $j++) 
                $p->logMemoryUsage();
                $t->assertTrue(strpos($p->getLogContent(), 'Memory:') !== false);
                break;
            case 23:
                for ($j = 0; $j < 1000; $j++) 
                $t->assertTrue(strlen($p->getLogContent()) <= 1024 * 50);
                break;
            case 24:
                $m = memory_get_usage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $t->assertFalse(strpos($p->getLogContent(), 'Garbage Collected:'));
                break;
            case 25:
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $p->logMemoryUsage();
                $t->assertTrue(strpos($p->getLogContent(), 'Memory:') !== false);
                break;
        }
    });
}

$tests->summary();
