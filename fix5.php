<?php

// === CONFIGURATION CLASS FOR GC BEHAVIOR ===
class GCConfig
{
    public static int $memoryThreshold = 10000; // threshold to trigger GC (not enforced in logic, placeholder)
    public static bool $debug = false; // toggles debug logging

    // Set custom memory threshold
    public static function setThreshold(int $bytes): void
    {
        self::$memoryThreshold = $bytes;
        self::log("Memory threshold set to {$bytes} bytes");
    }

    // Enable debug logging
    public static function enableDebug(): void
    {
        self::$debug = true;
        self::log("Debugging mode enabled");
    }

    // Conditional debug logger
    public static function log(string $msg): void
    {
        if (self::$debug) {
            echo "[DEBUG] $msg\n";
        }
    }
}

// === CORE REFERENCE MANAGER: TRACKS STRONG/WEAK REFS AND GRAPH ===
class RefManager
{
    private static array $strongRefs = []; // holds all strong references (prevents GC)
    private static array $weakRefs = [];   // holds weak refs (eligible for GC if not strongly referenced)
    private static array $graph = [];      // object reference graph (directed edges)

    // Add object to strong references
    public static function addStrong(RefObject $obj): void
    {
        self::$strongRefs[$obj->id()] = $obj;
        self::log("Added strong ref to {$obj->id()}");
    }

    // Add object to weak references
    public static function addWeak(RefObject $obj): void
    {
        self::$weakRefs[$obj->id()] = $obj;
        self::log("Added weak ref to {$obj->id()}");
    }

    // Release a strong reference (decrease ref count)
    public static function release(string $id): void
    {
        if (isset(self::$strongRefs[$id])) {
            self::$strongRefs[$id]->decrement();

            // If object is no longer referenced, remove from strong refs
            if (self::$strongRefs[$id]->getRefCount() === 0) {
                unset(self::$strongRefs[$id]);
            }
        }
    }

    // Create a directed edge in the graph from one object to another
    public static function connect(string $fromId, string $toId): void
    {
        self::$graph[$fromId][] = $toId;
        self::log("Connected {$fromId} -> {$toId}");
    }

    // Perform mark-and-sweep garbage collection
    public static function sweep(): void
    {
        self::log("Starting GC sweep...");

        $marked = [];

        // Start marking from all strongly referenced objects
        foreach (self::$strongRefs as $id => $obj) {
            self::mark($id, $marked);
        }

        // Sweep unmarked (unreachable) objects from weak refs and graph
        foreach (array_keys(self::$graph) as $id) {
            if (!in_array($id, $marked)) {
                self::log("Sweeping unreferenced object {$id}");
                unset(self::$weakRefs[$id]);
                unset(self::$graph[$id]);
            }
        }

        self::log("Sweep complete");
    }

    // Recursively mark all reachable objects in the graph
    private static function mark(string $id, array &$marked): void
    {
        if (in_array($id, $marked)) return;

        $marked[] = $id;

        foreach (self::$graph[$id] ?? [] as $child) {
            self::mark($child, $marked);
        }
    }

    // Internal helper for logging
    private static function log(string $msg): void
    {
        GCConfig::log($msg);
    }
}

// === OBJECT CLASS THAT SUPPORTS REFERENCE COUNTING ===
class RefObject
{
    private int $refCount = 1; // starts with 1 strong reference
    private string $id;

    public function __construct()
    {
        $this->id = spl_object_hash($this); // generate unique ID for tracking
        RefManager::addStrong($this);       // register with RefManager
        GCConfig::log("Created object {$this->id}");
    }

    // Increase reference count
    public function increment(): void
    {
        $this->refCount++;
        GCConfig::log("Incremented {$this->id} to {$this->refCount}");
    }

    // Decrease reference count; destroy if no references remain
    public function decrement(): void
    {
        $this->refCount--;
        GCConfig::log("Decremented {$this->id} to {$this->refCount}");

        if ($this->refCount <= 0) {
            $this->destroy();
        }
    }

    // Called when object is eligible for destruction
    private function destroy(): void
    {
        GCConfig::log("Destroyed object {$this->id}");
    }

    // Expose current ref count (for manager use)
    public function getRefCount(): int
    {
        return $this->refCount;
    }

    // Return object’s unique ID
    public function id(): string
    {
        return $this->id;
    }
}

// === DEMO / USAGE EXAMPLE ===

// Enable debugging output and set a (symbolic) memory threshold
GCConfig::enableDebug();
GCConfig::setThreshold(5000);

// Create three objects
$obj1 = new RefObject();
$obj2 = new RefObject();
$obj3 = new RefObject();

// Add extra strong references to obj2 and obj3
$obj2->increment();
$obj3->increment();

// Connect objects to form a cycle: 1 → 2 → 3 → 1
RefManager::connect($obj1->id(), $obj2->id());
RefManager::connect($obj2->id(), $obj3->id());
RefManager::connect($obj3->id(), $obj1->id());

// Release strong references
RefManager::release($obj1->id());
RefManager::release($obj2->id());
RefManager::release($obj3->id());

// Run mark-and-sweep GC: should detect cycle and clean it if no external strong refs remain
RefManager::sweep();
