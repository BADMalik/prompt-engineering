<?php

declare(ticks=1);

// Constants
define("NUM_THREADS", 5);
define("ITERATIONS_PER_THREAD", 100);
define("SHM_SIZE", 1024);
define("LOG_FILE", __DIR__ . "/thread_log.txt");

// Generate keys
$shmKey = ftok(__FILE__, 'm');
$semKey = ftok(__FILE__, 's');

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

// Setup cleanup on shutdown
function cleanup()
{
    global $shmId, $semaphore;
    if ($shmId) {
        shmop_delete($shmId);
        shmop_close($shmId);
    }
    if ($semaphore) {
        sem_remove($semaphore);
    }
    echo "Cleaned up shared memory and semaphore.\n";
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

// Logging function
function log_action($message)
{
    file_put_contents(LOG_FILE, "[" . date("H:i:s") . "] $message\n", FILE_APPEND);
}

// Spawn child processes
for ($i = 0; $i < NUM_THREADS; $i++) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("Failed to fork\n");
    } elseif ($pid == 0) {
        // Child process
        for ($j = 0; $j < ITERATIONS_PER_THREAD; $j++) {
            $startTime = microtime(true);

            // Acquire lock
            $acquired = sem_acquire($semaphore);
            $lockDuration = (microtime(true) - $startTime) * 1000;

            if ($acquired) {
                if ($lockDuration > 10) {
                    log_action("Thread " . getmypid() . " lock delay: {$lockDuration}ms");
                }

                // Read, increment, write
                $data = shmop_read($shmId, 0, 4);
                $counter = unpack("L", $data)[1];
                $counter++;
                shmop_write($shmId, pack("L", $counter), 0);

                log_action("Thread " . getmypid() . " incremented counter to $counter");

                sem_release($semaphore);
            }

            usleep(rand(100, 500)); // Simulate high-frequency activity
        }
        exit(0);
    } else {
        $children[] = $pid;
    }
}

// Wait for all children
foreach ($children as $childPid) {
    pcntl_waitpid($childPid, $status);
}

$final = unpack("L", shmop_read($shmId, 0, 4))[1];
echo "Final counter value: $final\n";
