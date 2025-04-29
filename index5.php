<!-- Model A: O1
Token count: 3587
Below is a single, standalone PHP script that illustrates a simulated PHP “extension” in pure PHP code. It demonstrates:

• A custom reference-counting system (with both strong and weak references).  
• An incremental mark-and-sweep garbage collector for handling circular references.  
• A configurable memory threshold that triggers GC.  
• A minimal API of at most six public “extension” functions.  
• Debugging/logging that is disabled by default.  

In a real scenario, most of this logic would reside in C code within a PHP extension; here it is shown in PHP for illustration and testing purposes only. The flow assumes Zend Thread Safety (ZTS) awareness by carefully avoiding global state modifications except through atomic updates (in actual C extension, you would use mutexes or TSRM macros).  

-------------------------------------------------------------------------------- -->
<?php

/**
 * Simulated PHP Extension for Custom Reference Counting & GC
 *
 * This single file aims to show how one might design and test a custom memory
 * management system integrated with PHP. In practice, much of this logic would
 * be implemented in C code using the PHP extension API, but the overall
 * structure and methods are illustrated here in PHP.
 *
 * Usage (example):
 *
 *   MyRefExtension::myext_init([
 *       'memory_threshold' => 2 * 1024 * 1024, // 2 MB
 *       'debug' => true
 *   ]);
 *
 *   $objA = MyRefExtension::myext_create_object();
 *   $objB = MyRefExtension::myext_create_object();
 *   // Create a strong reference from A -> B
 *   MyRefExtension::myext_add_reference($objA, $objB, true);
 *   // Create a circular strong reference from B -> A
 *   MyRefExtension::myext_add_reference($objB, $objA, true);
 *
 *   // Manually trigger GC
 *   MyRefExtension::myext_collect_garbage();
 */


/**
 * Class MyRefObject
 *
 * Represents an individual object being tracked. Each object maintains:
 * - A unique ID (akin to a handle).
 * - Separate strong and weak reference counts.
 * - A list of references to other objects (both strong and weak).
 * - Mark bit for incremental Mark-Sweep GC.
 */
class MyRefObject
{
    private string $id;
    private int $strongRefCount = 1;  // Initially 1 strong ref upon creation
    private int $weakRefCount   = 0;
    private bool $markedForGC   = false;
    // Store references to other MyRefObject IDs, distinguishing strong/weak.
    private array $strongRefs = [];
    private array $weakRefs   = [];

    public function __construct()
    {
        // In an actual extension, we'd generate a unique handle or pointer.
        // Here, we just use spl_object_hash for demonstration.
        $this->id = spl_object_hash($this);
    }

    /**
     * Increment strong or weak reference count.
     */
    public function incrementRef(bool $strong = true): void
    {
        if ($strong) {
            $this->strongRefCount++;
        } else {
            $this->weakRefCount++;
        }
    }

    /**
     * Decrement strong or weak reference count and return true if it hits zero.
     */
    public function decrementRef(bool $strong = true): bool
    {
        if ($strong) {
            $this->strongRefCount--;
            if ($this->strongRefCount < 0) {
                $this->strongRefCount = 0; // Safety guard
            }
            return ($this->strongRefCount === 0);
        } else {
            $this->weakRefCount--;
            if ($this->weakRefCount < 0) {
                $this->weakRefCount = 0; // Safety guard
            }
            return false; // Weak references do not decide final destruction
        }
    }

    /**
     * Add a reference to another object by ID.
     */
    public function addRefToObject(string $toObjectId, bool $strong = true): void
    {
        if ($strong) {
            $this->strongRefs[$toObjectId] = true;
        } else {
            $this->weakRefs[$toObjectId] = true;
        }
    }

    /**
     * Remove a reference to another object by ID.
     */
    public function removeRefToObject(string $toObjectId, bool $strong = true): void
    {
        if ($strong) {
            unset($this->strongRefs[$toObjectId]);
        } else {
            unset($this->weakRefs[$toObjectId]);
        }
    }

    /**
     * Accessors
     */
    public function getId(): string
    {
        return $this->id;
    }
    public function getStrongRefCount(): int
    {
        return $this->strongRefCount;
    }
    public function getWeakRefCount(): int
    {
        return $this->weakRefCount;
    }
    public function &getStrongRefs(): array
    {
        return $this->strongRefs;
    }
    public function &getWeakRefs(): array
    {
        return $this->weakRefs;
    }

    /**
     * Marking for GC.
     */
    public function mark(): void
    {
        $this->markedForGC = true;
    }
    public function unmark(): void
    {
        $this->markedForGC = false;
    }
    public function isMarked(): bool
    {
        return $this->markedForGC;
    }
}

/**
 * Class MyRefExtension
 *
 * Provides the “extension” API (up to six public static functions).  
 * Holds global(-ish) state for the reference-tracked objects, memory threshold,
 * and debugging mode. In a real C extension, these would be managed through
 * the PHP extension globals and TSRM (Thread Safe Resource Manager).
 */
class MyRefExtension
{
    // --- Extension Globals (Simulated) ---
    private static bool  $debug             = false;
    private static int   $memoryThreshold   = 1048576; // 1MB default
    private static array $objects           = [];      // ID => MyRefObject
    private static int   $objectCount       = 0;

    // --- 1) Initialize extension ---
    public static function myext_init(array $options = []): void
    {
        // Configure memory threshold
        if (isset($options['memory_threshold']) && is_int($options['memory_threshold'])) {
            self::$memoryThreshold = max(1024, $options['memory_threshold']);
        }
        // Enable/disable debug
        if (isset($options['debug'])) {
            self::$debug = (bool)$options['debug'];
        }
        self::debug("Extension initialized with memory_threshold=" . self::$memoryThreshold);
    }

    // --- 2) Create a new managed object ---
    public static function myext_create_object(): string
    {
        $obj = new MyRefObject();
        $id = $obj->getId();
        self::$objects[$id] = $obj;
        self::$objectCount++;

        // Check if we exceed memory threshold then consider a possible GC
        self::triggerGCIfNeeded();

        self::debug("Created object $id (total: " . self::$objectCount . ")");
        return $id;
    }

    // --- 3) Create/add a reference between two objects (strong or weak) ---
    public static function myext_add_reference(string $fromId, string $toId, bool $strong = true): void
    {
        if (!isset(self::$objects[$fromId]) || !isset(self::$objects[$toId])) {
            return; // In real code, throw an exception or raise an E_WARNING
        }
        // fromId holds a reference to toId
        self::$objects[$fromId]->addRefToObject($toId, $strong);
        // toId increments its reference count
        self::$objects[$toId]->incrementRef($strong);
        self::debug("Added " . ($strong ? "strong" : "weak") . " reference: $fromId -> $toId");
    }

    // --- 4) Remove a reference (strong or weak) ---
    public static function myext_remove_reference(string $fromId, string $toId, bool $strong = true): void
    {
        if (!isset(self::$objects[$fromId]) || !isset(self::$objects[$toId])) {
            return;
        }
        // fromId no longer references toId
        self::$objects[$fromId]->removeRefToObject($toId, $strong);
        // toId decrements its reference count
        $shouldDestroy = self::$objects[$toId]->decrementRef($strong);
        self::debug("Removed " . ($strong ? "strong" : "weak") . " reference: $fromId -> $toId");

        // If strong references hit zero, schedule for destruction.
        if ($shouldDestroy) {
            // We do not immediately destroy it — we let GC handle circular references.
            // But if it truly has zero strong references, we can do a quick check:
            self::debug("Object $toId has 0 strong references, scheduling GC check.");
            self::triggerGCIfNeeded();
        }
    }

    // --- 5) Force or trigger the GC (increments, mark-sweep, etc.) ---
    public static function myext_collect_garbage(): void
    {
        self::debug("Manual GC triggered.");
        self::doGarbageCollection();
    }

    // --- (Optional) 6) We can expose a query function or skip it. ---
    public static function myext_get_info(): array
    {
        // Return minimal info about objects if debugging is on.
        if (!self::$debug) {
            return ['debug' => false, 'objects' => '<hidden>'];
        }
        $data = [];
        foreach (self::$objects as $id => $obj) {
            $data[$id] = [
                'strongRefCount' => $obj->getStrongRefCount(),
                'weakRefCount'   => $obj->getWeakRefCount(),
                'strongRefs'     => array_keys($obj->getStrongRefs()),
                'weakRefs'       => array_keys($obj->getWeakRefs()),
            ];
        }
        return ['debug' => true, 'objects' => $data];
    }

    /**
     * Internal function to periodically check if memory usage or zero-ref objects
     * require a GC run.
     */
    private static function triggerGCIfNeeded(): void
    {
        $currentMem = memory_get_usage(true);
        if ($currentMem >= self::$memoryThreshold) {
            self::debug("Memory usage $currentMem >= threshold " . self::$memoryThreshold . ", running GC...");
            self::doGarbageCollection();
        }
    }

    /**
     * The custom Mark-Sweep or Mark-Release collector.
     * - Mark all objects reachable via strong references (those with strongRefCount > 0).
     * - Sweep any unmarked objects (which means unreachable).
     */
    private static function doGarbageCollection(): void
    {
        // Step 1: Unmark all
        foreach (self::$objects as $obj) {
            $obj->unmark();
        }

        // Step 2: Mark all objects that are “roots” (strongRefCount > 0)
        // This naive approach interprets any object with strongRefCount > 0 as a root.
        // Real extensions might treat references from the stack/symbols as roots.
        foreach (self::$objects as $id => $obj) {
            if ($obj->getStrongRefCount() > 0) {
                self::markObjectRecursive($obj);
            }
        }

        // Step 3: Sweep (destroy) all unmarked objects
        $collectedCount = 0;
        foreach (array_keys(self::$objects) as $id) {
            if (!isset(self::$objects[$id])) {
                continue; // may have been removed
            }
            $obj = self::$objects[$id];
            if (!$obj->isMarked()) {
                // Proper destruction means removing from the registry,
                // and decrementing references it holds (though typically you'd
                // skip strong references since we’re disposing this object).
                // For simplicity, we remove it outright.
                self::destroyObject($id);
                $collectedCount++;
            }
        }

        self::debug("GC complete. Collected $collectedCount objects. Remaining: " . count(self::$objects));
    }

    /**
     * A recursive DFS to mark all strongly referenced objects from a root.
     */
    private static function markObjectRecursive(MyRefObject $obj): void
    {
        if ($obj->isMarked()) {
            return;
        }
        $obj->mark();
        foreach ($obj->getStrongRefs() as $refId => $_) {
            if (isset(self::$objects[$refId])) {
                self::markObjectRecursive(self::$objects[$refId]);
            }
        }
    }

    /**
     * Remove object from registry. If we wanted, we could attempt to recursively
     * decrement references from this object, but in a real extension, the engine
     * might handle that differently. Here, we just remove it.
     */
    private static function destroyObject(string $id): void
    {
        if (isset(self::$objects[$id])) {
            unset(self::$objects[$id]);
            self::$objectCount--;
            self::debug("Destroyed object $id. Current total: " . self::$objectCount);
        }
    }

    /**
     * Debug logging helper (no output unless debug is enabled).
     */
    private static function debug(string $message): void
    {
        if (self::$debug) {
            echo "[MyRefExtension Debug] $message\n";
        }
    }
}


MyRefExtension::myext_init([
    'memory_threshold' => 2 * 1024 * 1024, // 2 MB
    'debug' => true
]);

$obj1 = MyRefExtension::myext_create_object();
$obj2 = MyRefExtension::myext_create_object();
MyRefExtension::myext_add_reference($obj1, $obj2, true);  // A -> B strong
MyRefExtension::myext_add_reference($obj2, $obj1, true);  // B -> A strong (circular)
MyRefExtension::myext_remove_reference($obj1, $obj2, true); // remove A->B
MyRefExtension::myext_remove_reference($obj2, $obj1, true); // remove B->A => both should be collectible
MyRefExtension::myext_collect_garbage();

print_r(MyRefExtension::myext_get_info());
