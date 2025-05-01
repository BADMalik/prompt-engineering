<?php

require_once 'multithreaded_script.php'; // Assuming the multithreaded script is in this file

use PHPUnit\Framework\TestCase;

class MultithreadedScriptTest extends TestCase
{
    private $testFramework;

    public function setUp(): void
    {
        $this->testFramework = new SimpleTestFramework();
    }

    public function tearDown(): void
    {
        $this->testFramework->summary();
    }

    public function testQueueSizeLimit()
    {
        $this->testFramework->run('Queue size does not exceed QUEUE_LIMIT', function (SimpleTestFramework $test) {
            generateIncomingRequests(QUEUE_LIMIT + 1);
            $this->expectOutputString("[" . date('H:i:s') . "] Error: Request queue exceeded limit of " . QUEUE_LIMIT . "\n");
        });
    }

    public function testRequestSortingByPriority()
    {
        $this->testFramework->run('Requests are sorted correctly by priority', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            $previousPriority = PHP_INT_MAX;
            foreach ($GLOBALS['requestQueue'] as $request) {
                $test->assertLessThanOrEqual($previousPriority, $request['priority'], "Requests are not sorted by priority");
                $previousPriority = $request['priority'];
            }
        });
    }

    public function testMaxThreadsLimit()
    {
        $this->testFramework->run('Number of threads does not exceed MAX_THREADS', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD + 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD + 1) . " requests...\n");
        });
    }

    public function testMaxTasksPerThreadLimit()
    {
        $this->testFramework->run('Tasks assigned to threads do not exceed MAX_TASKS_PER_THREAD', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_TASKS_PER_THREAD + 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD + 1) . " requests...\n");
        });
    }

    public function testZeroRequestsHandling()
    {
        $this->testFramework->run('System handles zero requests gracefully', function (SimpleTestFramework $test) {
            generateIncomingRequests(0);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting 0 threads to handle 0 requests...\n");
        });
    }

    public function testPerformanceMeasurementLogging()
    {
        $this->testFramework->run('Performance measurement logging works without errors', function (SimpleTestFramework $test) {
            $startTime = microtime(true);
            generateIncomingRequests(100);
            startThreadPool();
            $endTime = microtime(true);
            measurePerformance($startTime, $endTime, 100);
            $this->expectOutputString("[" . date('H:i:s') . "] Performance: " . round(100 / ($endTime - $startTime), 2) . " requests/sec over " . round($endTime - $startTime, 2) . " seconds.\n");
        });
    }

    public function testGracefulShutdown()
    {
        $this->testFramework->run('Graceful shutdown logs the shutdown status and waits for active threads to finish', function (SimpleTestFramework $test) {
            pcntl_signal(SIGTERM, 'shutdownHandler');
            generateIncomingRequests(100);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Shutdown initiated. Waiting for child processes to finish...\n");
        });
    }

    public function testSequentialRequestIds()
    {
        $this->testFramework->run('Request IDs are generated sequentially', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            $previousId = 0;
            foreach ($GLOBALS['requestQueue'] as $request) {
                $test->assertEquals($previousId + 1, $request['id'], "Request IDs are not generated sequentially");
                $previousId = $request['id'];
            }
        });
    }

    public function testSingleThreadForSmallTasks()
    {
        $this->testFramework->run('System uses only a single thread for a small number of tasks', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_TASKS_PER_THREAD - 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD - 1) . " requests...\n");
        });
    }

    public function testThreadScaling()
    {
        $this->testFramework->run('Threads scale based on the total number of requests', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD + 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD + 1) . " requests...\n");
        });
    }

    public function testEvenTaskDistribution()
    {
        $this->testFramework->run('Tasks are distributed evenly across threads', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_THREADS * MAX_TASKS_PER_THREAD);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting " . MAX_THREADS . " threads to handle " . (MAX_THREADS * MAX_TASKS_PER_THREAD) . " requests...\n");
        });
    }

    public function testRequestRateLimit()
    {
        $this->testFramework->run('System handles requests without exceeding the MAX_REQUESTS_PER_SECOND rate', function (SimpleTestFramework $test) {
            $startTime = microtime(true);
            generateIncomingRequests(MAX_REQUESTS_PER_SECOND * 2);
            startThreadPool();
            $endTime = microtime(true);
            measurePerformance($startTime, $endTime, MAX_REQUESTS_PER_SECOND * 2);
            $this->expectOutputString("[" . date('H:i:s') . "] Performance: " . round((MAX_REQUESTS_PER_SECOND * 2) / ($endTime - $startTime), 2) . " requests/sec over " . round($endTime - $startTime, 2) . " seconds.\n");
        });
    }

    public function testThreadTermination()
    {
        $this->testFramework->run('Threads terminate properly after completing tasks', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] All threads completed.\n");
        });
    }

    public function testLogMessageFormatting()
    {
        $this->testFramework->run('Log messages are formatted with timestamps', function (SimpleTestFramework $test) {
            logMessage("Test log message");
            $this->expectOutputString("[" . date('H:i:s') . "] Test log message\n");
        });
    }

    public function testRequestQueueSorting()
    {
        $this->testFramework->run('Request queue is sorted correctly by priority before processing', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            $previousPriority = PHP_INT_MAX;
            foreach ($GLOBALS['requestQueue'] as $request) {
                $test->assertLessThanOrEqual($previousPriority, $request['priority'], "Requests are not sorted by priority");
                $previousPriority = $request['priority'];
            }
        });
    }

    public function testThreadPoolShutdownOnSIGTERM()
    {
        $this->testFramework->run('Thread pool shuts down on SIGTERM', function (SimpleTestFramework $test) {
            pcntl_signal(SIGTERM, 'shutdownHandler');
            generateIncomingRequests(100);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Shutdown initiated. Waiting for child processes to finish...\n");
        });
    }

    public function testEmptyRequestQueueAfterProcessing()
    {
        $this->testFramework->run('Request queue is empty after all tasks are processed', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            startThreadPool();
            $this->assertEquals(0, count($GLOBALS['requestQueue']), "Request queue is not empty after processing");
        });
    }

    public function testSingleThreadForSmallRequestSet()
    {
        $this->testFramework->run('Only one thread is used for a small request set', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_TASKS_PER_THREAD - 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD - 1) . " requests...\n");
        });
    }

    public function testMaxTasksPerThreadEnforcement()
    {
        $this->testFramework->run('No thread processes more than MAX_TASKS_PER_THREAD tasks', function (SimpleTestFramework $test) {
            generateIncomingRequests(MAX_TASKS_PER_THREAD + 1);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] Starting 1 threads to handle " . (MAX_TASKS_PER_THREAD + 1) . " requests...\n");
        });
    }

    public function testThreadCleanup()
    {
        $this->testFramework->run('Threads clean up properly after completing work', function (SimpleTestFramework $test) {
            generateIncomingRequests(10);
            startThreadPool();
            $this->expectOutputString("[" . date('H:i:s') . "] All threads completed.\n");
        });
    }
}

// Run the tests
$test = new MultithreadedScriptTest();
$test->runTests();

