<?php

declare(ticks=1);
error_reporting(E_ALL);

// Constants
define("NUM_THREADS", 5);
define("ITERATIONS_PER_THREAD", 100);
define("SHM_SIZE", 1024);
define("LOG_FILE", __DIR__ . "/thread_log.txt");
define("FAULT_LOG_FILE", __DIR__ . "/fault_log.txt");
define("LOG_ROTATE_SIZE", 10240); // 10 KB
define("MAX_LOCK_WAIT_TIME", 50); // 50ms
define("MAX_LOCK_HOLD_TIME", 1000); // 1 second
define("MAX_RETRIES", 5);
define("RATE_LIMIT_THRESHOLD", 10); // Max attempts per second
define("LOCK_ESCALATION_THRESHOLD", 3);
define("LOCK_ESCALATION_TIMEOUT", 1000); // 1 second
define("MEMORY_CHECKSUM_INTERVAL", 10);
define("SNAPSHOT_INTERVAL", 20);
define("WATCHDOG_TIMEOUT", 2000); // 2 seconds
define("IO_WAIT_LOCK_FILE", __DIR__ . "/io_wait_lock.txt");

// Generate keys
$shmKey = ftok(__FILE__, 'm');
$semKey = ftok(__FILE__, 's');
$logSemKey = ftok(__FILE__, 'l');
$globalLockKey = ftok(__FILE__, 'g');

// Setup semaphores
$semaphore = sem_get($semKey, 1, 0666, 1);
$logSemaphore = sem_get($logSemKey, 1, 0666, 1);
$globalLock = sem_get($globalLockKey, 1, 0666, 1);
if (!$semaphore || !$logSemaphore || !$globalLock) {
    die("Failed to create semaphores\n");
}

// Setup shared memory
$shmId = shmop_open($shmKey, "c", 0666, SHM_SIZE);
if (!$shmId) {
    die("Failed to create shared memory\n");
}
shmop_write($shmId, pack("L", 0), 0); // Initialize counter

// Setup cleanup on shutdown
function cleanup()
{
    global $shmId, $semaphore, $logSemaphore, $globalLock;
    if ($shmId) {
        shmop_delete($shmId);
    }
    if ($semaphore) {
        sem_remove($semaphore);
    }
    if ($logSemaphore) {
        sem_remove($logSemaphore);
    }
    if ($globalLock) {
        sem_remove($globalLock);
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
            exit(0);
        case SIGUSR1:
            // Reload configuration or parameters
            break;
    }
}
pcntl_signal(SIGTERM, "signal_handler");
pcntl_signal(SIGINT, "signal_handler");
pcntl_signal(SIGUSR1, "signal_handler");

$children = [];
$lockRetries = [];
$lockSuccesses = [];
$lockWaitTimes = [];
$lastAccessTimestamps = [];
$memorySnapshots = [];
$config = [
    'thread_limit' => NUM_THREADS,
    'priority_policy' => 'FIFO', // or 'PRIORITY'
];

// Logging function
function log_action($message, $level = 'INFO')
{
    global $logSemaphore, $logRotateSize;
    $logFile = LOG_FILE;
    $fileSize = file_exists($logFile) ? filesize($logFile) : 0;

    if ($fileSize > $logRotateSize) {
        $logFile = LOG_FILE . "." . (substr_count(glob(LOG_FILE . ".*"), ".") + 1);
    }

    sem_acquire($logSemaphore);
    file_put_contents($logFile, "[" . date("H:i:s") . "] [$level] $message\n", FILE_APPEND);
    sem_release($logSemaphore);
}

// Fault logging function
function log_fault($message)
{
    global $logSemaphore;
    $faultLogFile = FAULT_LOG_FILE;
    sem_acquire($logSemaphore);
    file_put_contents($faultLogFile, "[" . date("H:i:s") . "] $message\n", FILE_APPEND);
    sem_release($logSemaphore);
}

// Transaction class
class Transaction
{
    private $semaphore;
    private $shmId;
    private $lockDepth = 0;

    public function __construct($semaphore, $shmId)
    {
        $this->semaphore = $semaphore;
        $this->shmId = $shmId;
    }

    public function start()
    {
        sem_acquire($this->semaphore);
        $this->lockDepth++;
    }

    public function commit()
    {
        if ($this->lockDepth > 0) {
            sem_release($this->semaphore);
            $this->lockDepth--;
        }
    }

    public function read()
    {
        return shmop_read($this->shmId, 0, 4);
    }

    public function write($data)
    {
        shmop_write($this->shmId, $data, 0);
    }
}

// ThreadManager class
class ThreadManager
{
    private $semaphore;
    private $shmId;
    private $logSemaphore;
    private $globalLock;
    private $children;
    private $lockRetries;
    private $lockSuccesses;
    private $lockWaitTimes;
    private $lastAccessTimestamps;
    private $memorySnapshots;
    private $hooks = [];

    public function __construct($semaphore, $shmId, $logSemaphore, $globalLock)
    {
        $this->semaphore = $semaphore;
        $this->shmId = $shmId;
        $this->logSemaphore = $logSemaphore;
        $this->globalLock = $globalLock;
        $this->children = [];
        $this->lockRetries = [];
        $this->lockSuccesses = [];
        $this->lockWaitTimes = [];
        $this->lastAccessTimestamps = [];
        $this->memorySnapshots = [];
    }

    public function addHook($event, $callback)
    {
        $this->hooks[$event][] = $callback;
    }

    private function triggerHook($event, $data = [])
    {
        if (isset($this->hooks[$event])) {
            foreach ($this->hooks[$event] as $callback) {
                call_user_func($callback, $data);
            }
        }
    }

    public function spawnThreads()
    {
        global $NUM_THREADS, $ITERATIONS_PER_THREAD, $MAX_LOCK_WAIT_TIME, $MAX_LOCK_HOLD_TIME, $MAX_RETRIES, $RATE_LIMIT_THRESHOLD, $LOCK_ESCALATION_THRESHOLD, $LOCK_ESCALATION_TIMEOUT, $MEMORY_CHECKSUM_INTERVAL, $SNAPSHOT_INTERVAL, $WATCHDOG_TIMEOUT, $config;

        $NUM_THREADS = $config['thread_limit'];

        for ($i = 0; $i < $NUM_THREADS; $i++) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                die("Failed to fork\n");
            } elseif ($pid == 0) {
                // Child process
                $this->childProcess($i, ITERATIONS_PER_THREAD, $MAX_LOCK_WAIT_TIME, $MAX_LOCK_HOLD_TIME, $MAX_RETRIES, $RATE_LIMIT_THRESHOLD, $LOCK_ESCALATION_THRESHOLD, $LOCK_ESCALATION_TIMEOUT, $MEMORY_CHECKSUM_INTERVAL, $SNAPSHOT_INTERVAL);
                exit(0);
            } else {
                $this->children[] = $pid;
            }
        }

        // Watchdog thread
        $this->watchdog();

        // Wait for all children
        foreach ($this->children as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        $this->printStatistics();
    }

    private function childProcess($threadId, $iterations, $maxLockWaitTime, $maxLockHoldTime, $maxRetries, $rateLimitThreshold, $lockEscalationThreshold, $lockEscalationTimeout, $memoryChecksumInterval, $snapshotInterval)
    {
        global $shmKey, $semKey, $logSemKey, $globalLockKey, $IO_WAIT_LOCK_FILE;

        $localSemaphore = sem_get($semKey, 1, 0666, 1);
        $localShmId = shmop_open($shmKey, "a", 0666, SHM_SIZE);
        $localLogSemaphore = sem_get($logSemKey, 1, 0666, 1);
        $localGlobalLock = sem_get($globalLockKey, 1, 0666, 1);
        $ioWaitLockFile = fopen($IO_WAIT_LOCK_FILE, "w+");

        $transaction = new Transaction($localSemaphore, $localShmId);
        $lockDepth = 0;
        $lastAccessTimestamp = microtime(true);
        $memoryAccessCount = 0;
        $snapshotCount = 0;

        for ($j = 0; $j < $iterations; $j++) {
            $startTime = microtime(true);
            $retries = 0;
            $success = false;

            while ($retries < $maxRetries && !$success) {
                // Simulate I/O wait lock
                flock($ioWaitLockFile, LOCK_EX);

                $acquired = sem_acquire($localSemaphore, false);
                $lockDuration = (microtime(true) - $startTime) * 1000;

                if ($acquired) {
                    if ($lockDuration > $maxLockWaitTime) {
                        log_action("Thread $threadId lock delay: {$lockDuration}ms", 'WARN');
                    }

                    $lockDepth++;
                    $this->lockSuccesses[$threadId] = ($this->lockSuccesses[$threadId] ?? 0) + 1;
                    $this->lockWaitTimes[$threadId] = ($this->lockWaitTimes[$threadId] ?? 0) + $lockDuration;

                    $transaction->start();

                    // Read, increment, write
                    $data = $transaction->read();
                    $counter = unpack("L", $data)[1];
                    $counter++;
                    $transaction->write(pack("L", $counter));

                    log_action("Thread $threadId incremented counter to $counter");

                    if ($threadId == 2 && $j == 32) {
                        log_action("Simulated crash for thread $threadId on iteration $j", 'ERROR');
                        posix_kill(getmypid(), SIGKILL);
                    }

                    $transaction->commit();

                    $success = true;
                    $memoryAccessCount++;

                    if ($memoryAccessCount % $memoryChecksumInterval == 0) {
                        $this->checkMemoryConsistency($localShmId, $counter);
                    }

                    if ($memoryAccessCount % $snapshotInterval == 0) {
                        $this->takeMemorySnapshot($localShmId, $snapshotCount++);
                    }
                } else {
                    $retries++;
                    $this->lockRetries[$threadId] = ($this->lockRetries[$threadId] ?? 0) + 1;
                    usleep(1000); // Backoff
                }

                flock($ioWaitLockFile, LOCK_UN);
            }

            if (!$success) {
                log_action("Thread $threadId failed to acquire lock after $maxRetries retries", 'ERROR');
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
                    log_action("Thread $threadId rate limited", 'WARN');
                    usleep(1000000); // Wait for 1 second
                }
                $this->lastAccessTimestamps[$threadId] = $accessCount;
            } else {
                $this->lastAccessTimestamps[$threadId] = 1;
                $lastAccessTimestamp = $currentTime;
            }

            // Lock escalation
            if ($lockDepth > $lockEscalationThreshold) {
                $this->escalateLock($localGlobalLock, $lockEscalationTimeout);
            }
        }
    }

    private function checkMemoryConsistency($shmId, $expectedCounter)
    {
        $data = shmop_read($shmId, 0, 4);
        $counter = unpack("L", $data)[1];
        if ($counter != $expectedCounter) {
            log_fault("Memory consistency check failed: expected $expectedCounter, got $counter");
        }
    }

    private function takeMemorySnapshot($shmId, $snapshotCount)
    {
        $data = shmop_read($shmId, 0, SHM_SIZE);
        $compressedData = gzcompress($data);
        $snapshotFile = __DIR__ . "/snapshots/snapshot_" . date("Ymd_His") . "_$snapshotCount.gz";
        file_put_contents($snapshotFile, $compressedData);
        log_action("Memory snapshot taken: $snapshotFile");
    }

    private function escalateLock($globalLock, $timeout)
    {
        $startTime = microtime(true);
        $acquired = sem_acquire($globalLock, false);
        $lockDuration = (microtime(true) - $startTime) * 1000;

        if ($acquired) {
            log_action("Lock escalated to global lock");
            if ($lockDuration > $timeout) {
                log_action("Global lock acquisition took too long: {$lockDuration}ms", 'WARN');
            }
            sem_release($globalLock);
        } else {
            log_action("Failed to escalate lock to global lock", 'ERROR');
        }
    }

    private function watchdog()
    {
        while (true) {
            sleep(1); // Check every second
            foreach ($this->children as $pid) {
                if (!posix_kill($pid, 0)) {
                    log_action("Thread $pid is unresponsive, terminating", 'ERROR');
                    posix_kill($pid, SIGKILL);
                } else {
                    $currentTime = microtime(true);
                    if ($currentTime - ($this->lastAccessTimestamps[$pid] ?? 0) > WATCHDOG_TIMEOUT / 1000) {
                        log_action("Thread $pid is unresponsive, forcibly unlocking", 'WARN');
                        sem_remove($this->semaphore);
                        sem_acquire($this->semaphore);
                    }
                }
            }
        }
    }

    private function printStatistics()
    {
        global $lockRetries, $lockSuccesses, $lockWaitTimes;
        echo "\033[32mStatistics:\033[0m\n";
        foreach (range(0, NUM_THREADS - 1) as $threadId) {
            echo "\033[33mThread $threadId:\033[0m\n";
            echo "  \033[34mRetries:\033[0m " . ($lockRetries[$threadId] ?? 0) . "\n";
            echo "  \033[34mSuccessful Accesses:\033[0m " . ($lockSuccesses[$threadId] ?? 0) . "\n";
            echo "  \033[34mTotal Wait Time:\033[0m " . ($lockWaitTimes[$threadId] ?? 0) . "ms\n";
        }
    }
}

// DiagnosticTool class
class DiagnosticTool
{
    private $threadManager;

    public function __construct(ThreadManager $threadManager)
    {
        $this->threadManager = $threadManager;
    }

    public function run()
    {
        while (true) {
            sleep(5); // Run every 5 seconds
            $this->aggregateStats();
            $this->performHealthChecks();
        }
    }

    private function aggregateStats()
    {
        // Aggregate statistics from threadManager
    }

    private function performHealthChecks()
    {
        // Perform health checks and log any issues
    }
}

// Main execution
$threadManager = new ThreadManager($semaphore, $shmId, $logSemaphore, $globalLock);

// Add event hooks
$threadManager->addHook('onLockAcquired', function ($data) {
    log_action("Lock acquired by thread {$data['threadId']}");
});
$threadManager->addHook('onLockTimeout', function ($data) {
    log_action("Lock timeout for thread {$data['threadId']}", 'WARN');
});
$threadManager->addHook('onDeadlock', function ($data) {
    log_action("Deadlock detected for thread {$data['threadId']}", 'ERROR');
});

// Start diagnostic tool
$diagnosticTool = new DiagnosticTool($threadManager);
pcntl_fork(function () use ($diagnosticTool) {
    $diagnosticTool->run();
});

// Spawn threads
$threadManager->spawnThreads();

exit(0); // Exit with code 0 if all tests pass
