<?php

// Setup initial shared memory and semaphore resources
$parentPid = posix_getpid();
$shmId = null;
$semaphore = null;
$logLock = null; // Semaphore for synchronized logging
$maxLogSize = 10240; // 10KB
$retryLimit = 3; // Max retry attempts before giving up
$rateLimit = 5; // Max number of lock requests per thread
$logFilePath = 'log_';

// Thread statistics
$threadStats = [
    'retries' => [],
    'successes' => [],
    'waitTime' => []
];

// ThreadManager class to manage threads, memory cleanup, and deadlock logging
class ThreadManager
{
    private $logLock;
    private $semaphore;
    private $shmId;
    private $shmKey;
    private $semaphoreKey;
    private $logFilePath;
    private $logFileIndex;

    public function __construct($shmKey, $semaphoreKey, $logFilePath)
    {
        $this->shmKey = $shmKey;
        $this->semaphoreKey = $semaphoreKey;
        $this->logFilePath = $logFilePath;
        $this->logFileIndex = 1; // Initialize log file index to 1

        // Create shared memory segment
        $this->shmId = shmop_open($shmKey, "c", 0644, 8);
        if (!$this->shmId) {
            die("Failed to create shared memory.");
        }

        // Create semaphore
        $this->semaphore = sem_get($semaphoreKey, 1, 0666, 1);
        if (!$this->semaphore) {
            die("Failed to create semaphore.");
        }

        // Create log lock for synchronized logging
        $this->logLock = sem_get($semaphoreKey + 1, 1, 0666, 1);
    }

    // Function to log messages with rotation
    public function logMessage($message)
    {
        sem_acquire($this->logLock); // Lock for log writing
        $currentLogFile = $this->getLogFilePath();

        // Check if log file exists before checking the size
        if (file_exists($currentLogFile) && filesize($currentLogFile) > $GLOBALS['maxLogSize']) {
            $this->rotateLogFile();
        }

        file_put_contents($currentLogFile, "[LOG] " . date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
        sem_release($this->logLock); // Release log lock
        echo $message . "\n";  // Print log to terminal as well
    }

    private function getLogFilePath()
    {
        return $this->logFilePath . $this->logFileIndex . ".txt";
    }

    private function rotateLogFile()
    {
        $this->logFileIndex++;
        if ($this->logFileIndex > 10) {
            $this->logFileIndex = 1; // Reset if exceeded 10 log files
        }
    }

    // Function to simulate thread operation with retries, backoff, and timeouts
    public function simulateThreadOperation($counter, $pid)
    {
        $retryCount = 0;
        $maxWaitTime = 0.05; // 50ms timeout for lock acquisition

        $startTime = microtime(true);
        while ($retryCount < $GLOBALS['retryLimit']) {
            $acquiredLock = $this->tryToAcquireLock($pid);
            $waitTime = microtime(true) - $startTime;

            // Timeout logic: If lock acquisition exceeds 50ms, stop and retry
            if ($waitTime > $maxWaitTime) {
                $GLOBALS['threadStats']['retries'][$pid] = $retryCount;
                $this->logMessage("Thread ($pid) failed to acquire lock within timeout.");
                break;
            }

            if ($acquiredLock) {
                $this->processThread($counter, $pid);
                break;
            } else {
                $retryCount++;
                $this->logMessage("Thread ($pid) retrying lock acquisition... Attempt $retryCount");
                usleep(pow(2, $retryCount) * 10000); // Exponential backoff
            }
        }
    }

    private function tryToAcquireLock($pid)
    {
        // Add logic for priority-based access (lower PID = higher priority)
        return sem_acquire($this->semaphore);
    }

    private function processThread($counter, $pid)
    {
        $counter = unpack("L", shmop_read($this->shmId, 0, shmop_size($this->shmId)))[1];

        // Simulate processing by incrementing the shared counter
        $counter++;
        $shmData = pack("L", $counter);
        shmop_write($this->shmId, $shmData, 0);

        $this->logMessage("Thread ($pid) incremented counter to: $counter");
        $GLOBALS['threadStats']['successes'][$pid] = $GLOBALS['threadStats']['successes'][$pid] ?? 0;
        $GLOBALS['threadStats']['successes'][$pid]++;
        sem_release($this->semaphore);
    }
}

$threadManager = new ThreadManager(ftok(__FILE__, 'r'), ftok(__FILE__, 's'), $GLOBALS['logFilePath']);

// Fork processes to simulate threads
for ($i = 0; $i < 5; $i++) {
    $pid = pcntl_fork();

    if ($pid == -1) {
        die("Fork failed.");
    } elseif ($pid == 0) {
        // Child process: simulate thread operation
        $threadManager->simulateThreadOperation($i, $pid);
        exit(); // Exit child process after completing its operation
    } else {
        // Parent process does nothing except for forking
        echo "Forked thread with PID $pid.\n"; // Log fork to terminal
    }
}

// Wait for all child processes to complete
for ($i = 0; $i < 5; $i++) {
    pcntl_wait($status);  // Wait for all children to exit
    echo "Child process $i finished.\n";  // Log child completion to terminal
}

// Output thread statistics
echo "Thread Statistics:\n";
foreach ($GLOBALS['threadStats']['retries'] as $pid => $retries) {
    echo "Thread $pid - Retries: $retries, Successes: " . $GLOBALS['threadStats']['successes'][$pid] . "\n";
}

$threadManager->logMessage("All threads (child processes) have finished.");
