<?php

declare(ticks=1);

// Constants
define("NUM_THREADS", 5);
define("ITERATIONS_PER_THREAD", 100);
define("SHM_SIZE", 1024);
define("LOG_FILE", __DIR__ . "/thread_log.txt");
define("LOG_ROTATE_SIZE", 10240); // 10 KB
define("MAX_LOCK_WAIT_TIME", 50); // 50ms
define("MAX_LOCK_HOLD_TIME", 1000); // 1 second
define("MAX_RETRIES", 5);
define("RATE_LIMIT_THRESHOLD", 10); // Max attempts per second

// Generate keys
$shmKey = ftok(__FILE__, 'm');
$semKey = ftok(__FILE__, 's');
$logSemKey = ftok(__FILE__, 'l');

// Setup semaphore
$semaphore = sem_get($semKey, 1, 0666, 1);
if (!$semaphore) {
    die("Failed to create semaphore\n");
}

// Setup shared memory
$shmId = shmop_open($shmKey, "c", 0666, SHM_SIZE);
if (!$shmId) {
    die("Failed to create shared memory\n");
}
shmop_write($shmId, pack("L", 0), 0); // Initialize counter

// Setup log semaphore
$logSemaphore = sem_get($logSemKey, 1, 0666, 1);
if (!$logSemaphore) {
    die("Failed to create log semaphore\n");
}

// Setup cleanup on shutdown
function cleanup()
{
    global $shmId, $semaphore, $logSemaphore;
    if ($shmId) {
        shmop_delete($shmId);
    }
    if ($semaphore) {
        sem_remove($semaphore);
    }
    if ($logSemaphore) {
        sem_remove($logSemaphore);
    }
    echo "Cleaned up shared memory and semaphores.\n";
}
register_shutdown_function('cleanup');

// Handle signals
function signal_handler($signal)
{
    global $children;
    switch ($signal) {
        case SIGTERM:
        case SIGINT:
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
            }
            exit;
    }
}
pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");

$children = [];
$lockRetries = [];
$lockSuccesses = [];
$lockWaitTimes = [];
$lastAccessTimestamps = [];

// Logging function
function log_action($message)
{
    global $logSemaphore, $logRotateSize;
    $logFile = LOG_FILE;
    $fileSize = file_exists($logFile) ? filesize($logFile) : 0;

    if ($fileSize > $logRotateSize) {
        $logFile = LOG_FILE . "." . (substr_count(glob(LOG_FILE . ".*"), ".") + 1);
    }

    sem_acquire($logSemaphore);
    file_put_contents($logFile, "[" . date("H:i:s") . "] $message\n", FILE_APPEND);
    sem_release($logSemaphore);
}

// ThreadManager class
class ThreadManager
{
    private $semaphore;
    private $shmId;
    private $logSemaphore;
    private $children;
    private $lockRetries;
    private $lockSuccesses;
    private $lockWaitTimes;
    private $lastAccessTimestamps;

    public function __construct($semaphore, $shmId, $logSemaphore)
    {
        $this->semaphore = $semaphore;
        $this->shmId = $shmId;
        $this->logSemaphore = $logSemaphore;
        $this->children = [];
        $this->lockRetries = [];
        $this->lockSuccesses = [];
        $this->lockWaitTimes = [];
        $this->lastAccessTimestamps = [];
    }

    public function spawnThreads()
    {
        global $NUM_THREADS, $ITERATIONS_PER_THREAD, $MAX_LOCK_WAIT_TIME, $MAX_LOCK_HOLD_TIME, $MAX_RETRIES, $RATE_LIMIT_THRESHOLD;

        for ($i = 0; $i < NUM_THREADS; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                die("Failed to fork\n");
            } elseif ($pid == 0) {
                // Child process
                $this->childProcess($i, ITERATIONS_PER_THREAD, $MAX_LOCK_WAIT_TIME, $MAX_LOCK_HOLD_TIME, $MAX_RETRIES, $RATE_LIMIT_THRESHOLD);
                exit(0);
            } else {
                $this->children[] = $pid;
            }
        }

        // Wait for all children
        foreach ($this->children as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        $this->printStatistics();
    }

    private function childProcess($threadId, $iterations, $maxLockWaitTime, $maxLockHoldTime, $maxRetries, $rateLimitThreshold)
    {
        global $shmKey, $semKey, $logSemKey;

        $localSemaphore = sem_get($semKey, 1, 0666, 1);
        $localShmId = shmop_open($shmKey, "a", 0666, SHM_SIZE);
        $localLogSemaphore = sem_get($logSemKey, 1, 0666, 1);

        $lockDepth = 0;
        $lastAccessTimestamp = microtime(true);

        for ($j = 0; $j < $iterations; $j++) {
            $startTime = microtime(true);
            $retries = 0;
            $success = false;

            while ($retries < $maxRetries && !$success) {
                $acquired = sem_acquire($localSemaphore, false);
                $lockDuration = (microtime(true) - $startTime) * 1000;

                if ($acquired) {
                    if ($lockDuration > $maxLockWaitTime) {
                        log_action("Thread $threadId lock delay: {$lockDuration}ms");
                    }

                    $lockDepth++;
                    $this->lockSuccesses[$threadId] = ($this->lockSuccesses[$threadId] ?? 0) + 1;
                    $this->lockWaitTimes[$threadId] = ($this->lockWaitTimes[$threadId] ?? 0) + $lockDuration;

                    // Read, increment, write
                    $data = shmop_read($localShmId, 0, 4);
                    $counter = unpack("L", $data)[1];
                    $counter++;
                    shmop_write($localShmId, pack("L", $counter), 0);

                    log_action("Thread $threadId incremented counter to $counter");

                    if ($threadId == 2 && $j == 32) {
                        log_action("Simulated crash for thread $threadId on iteration $j");
                        posix_kill(getmypid(), SIGKILL);
                    }

                    $success = true;
                } else {
                    $retries++;
                    $this->lockRetries[$threadId] = ($this->lockRetries[$threadId] ?? 0) + 1;
                    usleep(1000); // Backoff
                }
            }

            if (!$success) {
                log_action("Thread $threadId failed to acquire lock after $maxRetries retries");
            }

            if ($lockDepth > 0) {
                sem_release($localSemaphore);
                $lockDepth--;
            }

            usleep(rand(100, 500)); // Simulate high-frequency activity

            // Rate limiting
            $currentTime = microtime(true);
            if ($currentTime - $lastAccessTimestamp < 1) {
                $accessCount = ($this->lastAccessTimestamps[$threadId] ?? 0) + 1;
                if ($accessCount > $rateLimitThreshold) {
                    log_action("Thread $threadId rate limited");
                    usleep(1000000); // Wait for 1 second
                }
                $this->lastAccessTimestamps[$threadId] = $accessCount;
            } else {
                $this->lastAccessTimestamps[$threadId] = 1;
                $lastAccessTimestamp = $currentTime;
            }
        }
    }

    private function printStatistics()
    {
        global $lockRetries, $lockSuccesses, $lockWaitTimes;
        echo "Statistics:\n";
        foreach (range(0, NUM_THREADS - 1) as $threadId) {
            echo "Thread $threadId:\n";
            echo "  Retries: " . ($lockRetries[$threadId] ?? 0) . "\n";
            echo "  Successful Accesses: " . ($lockSuccesses[$threadId] ?? 0) . "\n";
            echo "  Total Wait Time: " . ($lockWaitTimes[$threadId] ?? 0) . "ms\n";
        }
    }
}

$threadManager = new ThreadManager($semaphore, $shmId, $logSemaphore);
$threadManager->spawnThreads();
