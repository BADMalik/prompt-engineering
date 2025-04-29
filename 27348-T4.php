<?php

class MemoryObject
{
    public int $id;
    public string $status = "free";
    public $data = null;
    public bool $locked = false;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

class MemoryPool
{
    private array $pool = [];
    private int $maxSize = 10;
    private int $allocationCount = 0;
    private int $deallocationCount = 0;
    private int $lockCount = 0;

    public function __construct()
    {
        for ($i = 0; $i < $this->maxSize; $i++) {
            $this->pool[$i] = new MemoryObject($i);
        }
    }

    public function allocate(): ?int
    {
        foreach ($this->pool as $obj) {
            if ($obj->status === "free" && !$obj->locked) {
                $obj->status = "used";
                $this->allocationCount++;
                echo "[SUCCESS] Allocated object ID {$obj->id}\n";
                return $obj->id;
            }
        }
        echo "[INFO] No free objects available for allocation\n";
        return null;
    }

    public function deallocate(int $id): void
    {
        if (!isset($this->pool[$id])) {
            echo "[ERROR] Invalid object ID {$id} for deallocation\n";
            return;
        }

        $obj = $this->pool[$id];
        if ($obj->status === "free") {
            echo "[INFO] Deallocating already free object ID {$id}\n";
            return;
        }

        if ($obj->locked) {
            echo "[ERROR] Cannot deallocate locked object ID {$id}\n";
            return;
        }

        $obj->status = "free";
        $this->deallocationCount++;
        echo "[INFO] Deallocated object ID {$id}\n";
    }

    public function lock(int $id): void
    {
        if (!isset($this->pool[$id])) {
            echo "[ERROR] Invalid object ID {$id} for locking\n";
            return;
        }

        $obj = $this->pool[$id];
        if ($obj->locked) {
            echo "[INFO] Object ID {$id} is already locked\n";
            return;
        }

        $obj->locked = true;
        $this->lockCount++;
        echo "[DEBUG] Locked ID {$id}\n";
    }

    public function unlock(int $id): void
    {
        if (!isset($this->pool[$id])) {
            echo "[ERROR] Invalid object ID {$id} for unlocking\n";
            return;
        }

        $obj = $this->pool[$id];
        if (!$obj->locked) {
            echo "[INFO] Object ID {$id} is already unlocked\n";
            return;
        }

        $obj->locked = false;
        echo "[INFO] Unlocked ID {$id}\n";
    }

    public function get(int $id): ?mixed
    {
        if (!isset($this->pool[$id])) {
            echo "[ERROR] Invalid object ID {$id} for getting data\n";
            return null;
        }

        $obj = $this->pool[$id];
        if ($obj->status === "free" || $obj->status === "corrupt") {
            echo "[INFO] Object ID {$id} is not usable, no data available\n";
            return null;
        }

        return $obj->data;
    }

    public function set(int $id, $data): void
    {
        if (!isset($this->pool[$id])) {
            echo "[ERROR] Invalid object ID {$id} for setting data\n";
            return;
        }

        $obj = $this->pool[$id];
        if ($obj->status === "free" || $obj->status === "corrupt") {
            echo "[ERROR] Cannot set data on free or corrupt object ID {$id}\n";
            return;
        }

        $obj->data = $data;
    }

    public function simulateLeak(): void
    {
        $id = rand(100, 999);
        echo "[SIM] Leaking object {$id}\n";
        // Simulate a memory leak by not deallocating an object
    }

    public function forceUnlockAll(): void
    {
        foreach ($this->pool as &$obj) {
            if ($obj->status !== "corrupt") {
                $obj->locked = false;
            }
        }
        echo "[WARNING] Force unlocked all non-corrupt objects (unsafe)\n";
    }

    public function reset(): void
    {
        foreach ($this->pool as &$obj) {
            $obj->status = "free";
            $obj->data = null;
            $obj->locked = false;
        }
        echo "[INFO] Reset all objects\n";
    }

    public function corruptRandom(): void
    {
        $rand = rand(0, $this->maxSize - 1);
        $this->pool[$rand]->status = "corrupt";
        echo "[CORRUPT] Modified object ID {$rand} to invalid state\n";
    }

    public function stats(): void
    {
        echo "Pool Size: " . count($this->pool) . "\n";
        echo "Allocations: {$this->allocationCount}\n";
        echo "Deallocations: {$this->deallocationCount}\n";
        echo "Locks: {$this->lockCount}\n";
    }

    public function exportState(): string
    {
        return json_encode(array_map(function ($obj) {
            return [
                'id' => $obj->id,
                'status' => $obj->status,
                'data' => $obj->data,
                'locked' => $obj->locked,
            ];
        }, $this->pool));
    }

    public function importState(string $json): void
    {
        $data = json_decode($json, true);
        $this->pool = array_map(function ($item) {
            $obj = new MemoryObject($item['id']);
            $obj->status = $item['status'];
            $obj->data = $item['data'];
            $obj->locked = $item['locked'];
            return $obj;
        }, $data);
        echo "[LOAD] Imported memory pool\n";
    }

    public function misuseAccess(): void
    {
        $rand = rand(0, $this->maxSize - 1);
        $this->pool[$rand]->status = "unknown";
        echo "[MISUSE] Accessed object {$rand} in undefined mode\n";
    }

    public function simulateRace(): void
    {
        $id = rand(0, $this->maxSize - 1);
        $this->lock($id);
        // Simulate a race condition by attempting to deallocate while locked
        $this->deallocate($id);
        $this->unlock($id);
    }

    public function debug(): void
    {
        print_r($this->pool);
    }
}

$pool = new MemoryPool();

$a = $pool->allocate();
$b = $pool->allocate();

$pool->lock($a);
$pool->set($a, "Data A");
$pool->unlock($a);
$pool->get($a);

$pool->deallocate($b);
$pool->deallocate($a);
$pool->deallocate($a);

$pool->simulateLeak();
$pool->simulateLeak();
$pool->forceUnlockAll();

$pool->corruptRandom();
$pool->simulateRace();

$pool->stats();
