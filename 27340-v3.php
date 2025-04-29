<?php

// Include necessary classes and functions for PHP

// Setup initial shared memory and semaphore resources
$parentPid = posix_getpid();
$shmId = null;
$semaphore = null;
$logLock = null; // Semaphore for synchronized logging
$maxLogSize = 10240; // 10KB
$retryLimit = 3; // Max retry attempts before giving up
$rateLimit = 5; // Max number of lock requests per thread
$logFilePath = 'log_';
$lockQueue = [];
$fairnessPolicy = 'FIFO'; // Configurable fairness policy

// Thread statistics
$threadStats = [
    'retries' => [],
    'successes' => [],
    'waitTime' => []
];

// Transaction class to wrap atomic operations
class Transaction
{
    private $shmId;
    private $semaphore;
    private $operationCount = 0;

    public function __construct($shmId, $semaphore)
    {
        $this->shmId = $shmId;
        $this->semaphore = $semaphore;
    }

    // Start a transaction for read-modify-write operations
    public function start()
    {
        if (!sem_acquire($this->semaphore)) {
            throw new Exception("Unable to acquire semaphore.");
        }
        echo "Transaction started.\n";
    }

    // Commit the transaction and release the semaphore
    public function commit()
    {
        sem_release($this->semaphore);
        echo "Transaction committed.\n";
    }

    // Perform a read-modify-write operation on shared memory
    public function performOperation($operation)
    {
        $this->start();
        // Read operation
        $currentData = shmop_read($this->shmId, 0, shmop_size($this->shmId));
        $currentData = unpack("L", $currentData)[1];

        // Modify operation (e.g., increment)
        $newData = $operation($currentData);

        // Write the new data back
        $newDataPacked = pack("L", $newData);
        shmop_write($this->shmId, $newDataPacked, 0);

        $this->operationCount++;
        if ($this->operationCount % 10 == 0) {
            $this->checkMemoryConsistency();
        }

        $this->commit();
    }

    // Memory consistency check after every 10 operations
    private function checkMemoryConsistency()
    {
        echo "Performing memory consistency check...\n";
        $currentData = shmop_read($this->shmId, 0, shmop_size($this->shmId));
        $hash = md5($currentData);
        echo "Memory consistency hash: $hash\n";
    }
}

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
    private $transaction;

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

        // Initialize transaction for read-modify-write operations
        $this->transaction = new Transaction($this->shmId, $this->semaphore);
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

    // Watchdog thread that monitors active threads
    public function watchdog($pid)
    {
        // Simulate monitoring threads for unresponsiveness
        $startTime = microtime(true);
        while (microtime(true) - $startTime < 2) {
            // Watchdog intervention logic
            if (microtime(true) - $startTime > 2) {
                $this->logMessage("Watchdog detected thread ($pid) as unresponsive. Forcing unlock.");
                sem_release($this->semaphore); // Force unlock if thread is unresponsive
                break;
            }
        }
    }

    // Fork processes to simulate threads
    public function forkThread($counter, $pid)
    {
        $this->transaction->performOperation(function ($data) {
            return $data + 1; // Sample operation: increment the counter
        });
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
        $threadManager->forkThread($i, $pid);
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
