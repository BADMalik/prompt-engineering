<?php

// Configuration
define('MAX_THREADS', 100);
define('QUEUE_LIMIT', 10000);
define('MAX_TASKS_PER_THREAD', 2000);
define('MAX_REQUESTS_PER_SECOND', 1000);
define('SHUTDOWN_SIGNAL', SIGTERM);

// Thread Pool State
$activeThreads = [];
$requestQueue = [];

// Logger
function logMessage($msg)
{
    echo "[" . date('H:i:s') . "] $msg\n";
}

// Simulate incoming HTTP request task with optional priority
function generateIncomingRequests($count = 10000)
{
    global $requestQueue;
    $requestQueue = [];
    for ($i = 0; $i < $count; $i++) {
        $priority = rand(1, 10); // 1 is highest
        $requestQueue[] = ['id' => $i + 1, 'priority' => $priority];
    }

    usort($requestQueue, function ($a, $b) {
        return $a['priority'] - $b['priority'];
    });
}

// Graceful shutdown handler
function shutdownHandler()
{
    global $activeThreads;
    logMessage("Shutdown initiated. Waiting for child processes to finish...");
    foreach ($activeThreads as $pid) {
        waitForChild($pid, $status);
    }
    logMessage("All threads have exited. Shutdown complete.");
    exit(0);
}

// Wait for child wrapper
function waitForChild($pid, &$status)
{
    return pcntl_waitpid($pid, $status);
}

// Task handler
function handleRequestTask($task)
{
    usleep(rand(500, 2000));
    logMessage("Processed request ID {$task['id']} with priority {$task['priority']}");
}

// Worker logic
function workerThread($tasks)
{
    $processed = 0;
    foreach ($tasks as $task) {
        handleRequestTask($task);
        $processed++;
        if ($processed >= MAX_TASKS_PER_THREAD) break;
    }
    exit(0);
}

// Main Pool Manager
function startThreadPool()
{
    global $requestQueue, $activeThreads;

    $totalRequests = count($requestQueue);
    if ($totalRequests > QUEUE_LIMIT) {
        logMessage("Error: Request queue exceeded limit of " . QUEUE_LIMIT);
        return;
    }

    $threadsNeeded = min(MAX_THREADS, ceil($totalRequests / MAX_TASKS_PER_THREAD));
    $tasksPerThread = ceil($totalRequests / $threadsNeeded);

    logMessage("Starting $threadsNeeded threads to handle $totalRequests requests...");

    for ($i = 0; $i < $threadsNeeded; $i++) {
        $start = $i * $tasksPerThread;
        $batch = array_slice($requestQueue, $start, $tasksPerThread);

        $pid = pcntl_fork();
        if ($pid == -1) {
            logMessage("Error: Failed to fork process.");
            continue;
        } elseif ($pid) {
            $activeThreads[] = $pid;
        } else {
            workerThread($batch);
        }
    }

    foreach ($activeThreads as $pid) {
        waitForChild($pid, $status);
    }

    logMessage("All threads completed.");
}

function measurePerformance($startTime, $endTime, $requestCount)
{
    $duration = $endTime - $startTime;
    $rps = $requestCount / $duration;
    logMessage("Performance: $rps requests/sec over $duration seconds.");

    if ($rps < MAX_REQUESTS_PER_SECOND * 0.9) {
        logMessage("Warning: Performance dropped below 90% of target rate.");
    }
}

pcntl_signal(SHUTDOWN_SIGNAL, 'shutdownHandler');

// === Minimal Test Framework ===
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

    public function assertEquals($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new Exception($message ?: "Expected $expected, got $actual");
        }
    }

    public function assertLessThanOrEqual($expected, $actual, $message = '')
    {
        if ($actual > $expected) {
            throw new Exception($message ?: "Expected $actual <= $expected");
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

// Test cases
$testFramework = new SimpleTestFramework();

$testFramework->run('Queue size does not exceed QUEUE_LIMIT', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(QUEUE_LIMIT + 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Error: Request queue exceeded limit of " . QUEUE_LIMIT, $output);
});

$testFramework->run('Requests are sorted correctly by priority', function (SimpleTestFramework $test) {
    generateIncomingRequests(10);
    $previousPriority = PHP_INT_MAX;
    foreach ($GLOBALS['requestQueue'] as $request) {
        $test->assertLessThanOrEqual($previousPriority, $request['priority'], "Requests are not sorted by priority");
        $previousPriority = $request['priority'];
    }
});

$testFramework->run('Number of threads does not exceed MAX_THREADS', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD + 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD + 1) . " requests...", $output);
});

$testFramework->run('Tasks assigned to threads do not exceed MAX_TASKS_PER_THREAD', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_TASKS_PER_THREAD + 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD + 1) . " requests...", $output);
});

$testFramework->run('System handles zero requests gracefully', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(0);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting 0 threads to handle 0 requests...", $output);
});

$testFramework->run('Performance measurement logging works without errors', function (SimpleTestFramework $test) {
    $startTime = microtime(true);
    generateIncomingRequests(100);
    startThreadPool();
    $endTime = microtime(true);
    ob_start();
    measurePerformance($startTime, $endTime, 100);
    $output = ob_get_clean();
    $expectedOutput = "Performance: " . round(100 / ($endTime - $startTime), 2) . " requests/sec over " . round($endTime - $startTime, 2) . " seconds.";
    $test->assertStringContainsString($expectedOutput, $output);
});

$testFramework->run('Graceful shutdown logs the shutdown status and waits for active threads to finish', function (SimpleTestFramework $test) {
    pcntl_signal(SIGTERM, 'shutdownHandler');
    ob_start();
    generateIncomingRequests(100);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Shutdown initiated. Waiting for child processes to finish...", $output);
});

$testFramework->run('Request IDs are generated sequentially', function (SimpleTestFramework $test) {
    generateIncomingRequests(10);
    $previousId = 0;
    foreach ($GLOBALS['requestQueue'] as $request) {
        $test->assertEquals($previousId + 1, $request['id'], "Request IDs are not generated sequentially");
        $previousId = $request['id'];
    }
});

$testFramework->run('System uses only a single thread for a small number of tasks', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_TASKS_PER_THREAD - 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD - 1) . " requests...", $output);
});

$testFramework->run('Threads scale based on the total number of requests', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD + 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD + 1) . " requests...", $output);
});

$testFramework->run('Tasks are distributed evenly across threads', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD) . " requests...", $output);
});

$testFramework->run('System handles requests without exceeding the MAX_REQUESTS_PER_SECOND rate', function (SimpleTestFramework $test) {
    $startTime = microtime(true);
    generateIncomingRequests(MAX_REQUESTS_PER_SECOND * 2);
    startThreadPool();
    $endTime = microtime(true);
    ob_start();
    measurePerformance($startTime, $endTime, MAX_REQUESTS_PER_SECOND * 2);
    $output = ob_get_clean();
    $expectedOutput = "Performance: " . round((MAX_REQUESTS_PER_SECOND * 2) / ($endTime - $startTime), 2) . " requests/sec over " . round($endTime - $startTime, 2) . " seconds.";
    $test->assertStringContainsString($expectedOutput, $output);
});

$testFramework->run('Threads terminate properly after completing tasks', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(10);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("All threads completed.", $output);
});

$testFramework->run('Log messages are formatted with timestamps', function (SimpleTestFramework $test) {
    ob_start();
    logMessage("Test log message");
    $output = ob_get_clean();
    $test->assertStringContainsString("[" . date('H:i:s') . "] Test log message", $output);
});

$testFramework->run('Request queue is sorted correctly by priority before processing', function (SimpleTestFramework $test) {
    generateIncomingRequests(10);
    $previousPriority = PHP_INT_MAX;
    foreach ($GLOBALS['requestQueue'] as $request) {
        $test->assertLessThanOrEqual($previousPriority, $request['priority'], "Requests are not sorted by priority");
        $previousPriority = $request['priority'];
    }
});

$testFramework->run('Thread pool shuts down on SIGTERM', function (SimpleTestFramework $test) {
    pcntl_signal(SIGTERM, 'shutdownHandler');
    ob_start();
    generateIncomingRequests(100);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Shutdown initiated. Waiting for child processes to finish...", $output);
});

$testFramework->run('Request queue is empty after all tasks are processed', function (SimpleTestFramework $test) {
    generateIncomingRequests(10);
    startThreadPool();
    $test->assertEquals(0, count($GLOBALS['requestQueue']), "Request queue is not empty after processing");
});

$testFramework->run('Only one thread is used for a small request set', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_TASKS_PER_THREAD - 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD - 1) . " requests...", $output);
});

$testFramework->run('No thread processes more than MAX_TASKS_PER_THREAD tasks', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(MAX_TASKS_PER_THREAD + 1);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD + 1) . " requests...", $output);
});

$testFramework->run('Threads clean up properly after completing work', function (SimpleTestFramework $test) {
    ob_start();
    generateIncomingRequests(10);
    startThreadPool();
    $output = ob_get_clean();
    $test->assertStringContainsString("All threads completed.", $output);
});

$testFramework->summary();
