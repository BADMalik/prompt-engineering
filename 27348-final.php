<?php

/**
 * Full-Scale Custom Memory Pool in Standalone PHP
 * Implements 16 features for advanced memory reuse, lifecycle, locking, TTL expiration, stats, and misuse validation
 */

// === CONFIGURATION ===
define('POOL_SIZE', 5);
define('LEAK_TTL', 4); // seconds
define('STATS_INTERVAL', 8); // seconds
define('LOG_FILE', 'memory_pool.log');
define('STATS_FILE', 'memory_stats.log');

// === ANSI TERMINAL LOGGING ===
function logToTerminal(string $message, string $level = 'INFO')
{
    $colors = [
        'INFO' => "\033[1;34m",
        'SUCCESS' => "\033[1;32m",
        'WARNING' => "\033[1;33m",
        'ERROR' => "\033[1;31m",
        'RESET' => "\033[0m"
    ];
    $color = $colors[$level] ?? $colors['INFO'];
    echo "{$color}[$level] $message{$colors['RESET']}\n";
}

function logToFile(string $msg)
{
    file_put_contents(LOG_FILE, date("[Y-m-d H:i:s] ") . $msg . PHP_EOL, FILE_APPEND);
}

function logStats(array $data)
{
    file_put_contents(STATS_FILE, date("[Y-m-d H:i:s] ") . json_encode($data) . PHP_EOL, FILE_APPEND);
}

// === MEMORY OBJECT CLASS ===
class MemoryObject
{
    public int $id;
    public string $state = 'free'; // free, in_use, locked, expired
    public mixed $payload = null;
    public float $lastUsed;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->lastUsed = microtime(true);
    }

    public function reset(): void
    {
        $this->payload = null;
        $this->lastUsed = microtime(true);
    }
}

// === MEMORY POOL CLASS ===
class MemoryPool
{
    private array $pool = [];
    private int $lastStatTime = 0;
    private int $violationCount = 0;

    public function __construct(private int $size)
    {
        for ($i = 0; $i < $size; $i++) {
            $this->pool[$i] = new MemoryObject($i);
        }
    }

    public function allocate(): MemoryObject
    {
        foreach ($this->pool as $obj) {
            if ($obj->state === 'free') {
                $obj->state = 'in_use';
                $obj->reset();
                logToTerminal("Allocated object ID {$obj->id}", 'SUCCESS');
                logToFile("ALLOCATE ID {$obj->id}");
                return $obj;
            }
        }
        $this->logViolation("No free objects to allocate");
        throw new RuntimeException("Memory exhausted.");
    }

    public function deallocate(MemoryObject $obj): void
    {
        $this->validateObject($obj);
        if ($obj->state !== 'in_use') {
            $this->logViolation("Cannot deallocate object ID {$obj->id} in state {$obj->state}");
            throw new LogicException("Invalid deallocation");
        }
        $obj->reset();
        $obj->state = 'free';
        logToTerminal("Deallocated object ID {$obj->id}", 'INFO');
        logToFile("DEALLOCATE ID {$obj->id}");
    }

    public function lock(int $id): void
    {
        $this->validateId($id);
        $obj = $this->pool[$id];
        if ($obj->state !== 'in_use') {
            $this->logViolation("Cannot lock object ID $id in state {$obj->state}");
            throw new LogicException("Lock error");
        }
        $obj->state = 'locked';
        logToTerminal("Locked object ID $id", 'INFO');
        logToFile("LOCK ID $id");
    }

    public function unlock(int $id): void
    {
        $this->validateId($id);
        $obj = $this->pool[$id];
        if ($obj->state !== 'locked') {
            $this->logViolation("Cannot unlock object ID $id in state {$obj->state}");
            throw new LogicException("Unlock error");
        }
        $obj->state = 'in_use';
        logToTerminal("Unlocked object ID $id", 'INFO');
        logToFile("UNLOCK ID $id");
    }

    public function cleanupExpired(): void
    {
        $now = microtime(true);
        foreach ($this->pool as $obj) {
            if (in_array($obj->state, ['in_use', 'locked']) && ($now - $obj->lastUsed) > LEAK_TTL) {
                $obj->state = 'expired';
                logToTerminal("Object ID {$obj->id} expired (TTL exceeded)", 'WARNING');
                logToFile("EXPIRE ID {$obj->id}");
            }
        }
    }

    public function reportStats(): void
    {
        $now = time();
        if (($now - $this->lastStatTime) >= STATS_INTERVAL) {
            $stats = ['free' => 0, 'in_use' => 0, 'locked' => 0, 'expired' => 0];
            foreach ($this->pool as $obj) {
                $stats[$obj->state]++;
            }
            $stats['violations'] = $this->violationCount;
            logStats($stats);
            logToTerminal("Stats: " . json_encode($stats), 'INFO');
            $this->lastStatTime = $now;
        }
    }

    public function forceReclaimExpired(): void
    {
        foreach ($this->pool as $obj) {
            if ($obj->state === 'expired') {
                $obj->reset();
                $obj->state = 'free';
                logToTerminal("Reclaimed expired object ID {$obj->id}", 'SUCCESS');
                logToFile("RECLAIM ID {$obj->id}");
            }
        }
    }

    private function validateObject(MemoryObject $obj): void
    {
        if (!isset($this->pool[$obj->id]) || $this->pool[$obj->id] !== $obj) {
            $this->logViolation("Invalid object ID: {$obj->id}");
            throw new InvalidArgumentException("Invalid object reference.");
        }
    }

    private function validateId(int $id): void
    {
        if (!isset($this->pool[$id])) {
            $this->logViolation("Invalid object ID: $id");
            throw new OutOfBoundsException("Invalid ID.");
        }
    }

    private function logViolation(string $msg): void
    {
        $this->violationCount++;
        logToTerminal("Violation: $msg", 'ERROR');
        logToFile("VIOLATION: $msg");
    }
}

// === DEMO SIMULATION ===
$pool = new MemoryPool(POOL_SIZE);

try {
    $a = $pool->allocate();
    $a->payload = "Data A";

    $b = $pool->allocate();
    $pool->lock($b->id);

    sleep(2);
    $pool->unlock($b->id);
    $pool->deallocate($b);

    $pool->deallocate($a);

    // Violation demo: double free
    $pool->deallocate($a); // should trigger violation

    // Trigger expiration
    $x = $pool->allocate();
    sleep(LEAK_TTL + 1);
    $pool->cleanupExpired();
    $pool->forceReclaimExpired();

    // Stats
    $pool->reportStats();
} catch (Exception $e) {
    logToTerminal("Exception: " . $e->getMessage(), 'ERROR');
}
