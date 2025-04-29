<?php
/**
 * Memory Leak Simulator in PHP
 *
 * This standalone script demonstrates a manual reference-counting system
 * in PHP, simulates memory leaks via circular references, detects them,
 * and applies a cleanup strategy. It provides a simple CLI-based user
 * interface for monitoring and interacting with the simulation.
 *
 * Usage (run in console):
 *   php MemoryLeakSimulator.php
 *   Then follow the on-screen prompts or enter commands (help, create, leak, show, detect, gc, exit).
 *
 * IMPORTANT:
 * - This is a demonstration of manual reference counting.
 * - It does NOT rely on PHP's internal garbage collector for collection,
 *   but rather simulates object lifecycle via arrays and counters.
 * - Circular references are created deliberately to simulate memory leaks.
 */

class ObjectManager
{
    /**
     * @var array<int, array> Stores object data and references in a structured array.
     *      Each key is an integer object ID, each value is an array:
     *        [
     *           'data' => mixed,            // The "payload" or data for demonstration
     *           'references' => int[],      // IDs of objects this object references
     *        ]
     */
    private array $objects = [];

    /**
     * @var array<int, int> Tracks the reference count for each object ID.
     *      The keys match $objects; the values are the reference counts.
     */
    private array $refCounts = [];

    /**
     * @var array<int> A set of "root" object IDs that are considered in-use externally.
     *      If an object is not reachable from any of these, it is considered "orphaned".
     */
    private array $rootReferences = [];

    /**
     * The next available numeric ID to assign to a newly created object.
     */
    private int $currentId = 1;

    /**
     * Create a new object in the manager.
     *
     * @param mixed $data Optional data payload for demonstration.
     * @param bool  $addAsRoot Whether to add this new object to the root set (default true).
     * @return int  Returns the ID of the newly created object.
     */
    public function createObject(mixed $data = null, bool $addAsRoot = true): int
    {
        $id = $this->currentId++;
        $this->objects[$id] = [
            'data'       => $data,
            'references' => []
        ];
        $this->refCounts[$id] = 0;

        // If we add it as a root object, it has one "external" reference
        // (meaning external to the system).
        if ($addAsRoot) {
            $this->rootReferences[] = $id;
            $this->increaseRefCount($id);
        }

        return $id;
    }

    /**
     * Increments the reference count of an object.
     */
    private function increaseRefCount(int $objectId): void
    {
        if (isset($this->refCounts[$objectId])) {
            $this->refCounts[$objectId]++;
        }
    }

    /**
     * Decrements the reference count of an object, and frees it if refcount hits zero.
     */
    private function decreaseRefCount(int $objectId): void
    {
        if (!isset($this->refCounts[$objectId])) {
            return;
        }

        $this->refCounts[$objectId]--;

        if ($this->refCounts[$objectId] <= 0) {
            // Reference count is zero: clean up immediately
            $this->freeObject($objectId);
        }
    }

    /**
     * Explicitly free an object, removing it from manager and decrementing
     * ref counts of anything it references.
     */
    private function freeObject(int $objectId): void
    {
        if (!isset($this->objects[$objectId])) {
            return;
        }

        // Decrement the refcount of each object it references
        foreach ($this->objects[$objectId]['references'] as $refId) {
            $this->decreaseRefCount($refId);
        }

        // Completely remove it from the system
        unset($this->objects[$objectId]);
        unset($this->refCounts[$objectId]);

        // Remove from rootReferences if present
        $idx = array_search($objectId, $this->rootReferences, true);
        if ($idx !== false) {
            unset($this->rootReferences[$idx]);
        }
    }

    /**
     * Add a reference from one object to another, incrementing target's refcount.
     *
     * @param int $fromObjectId
     * @param int $toObjectId
     */
    public function addReference(int $fromObjectId, int $toObjectId): void
    {
        if (!isset($this->objects[$fromObjectId]) || !isset($this->objects[$toObjectId])) {
            echo "Cannot add reference: invalid object ID(s).\n";
            return;
        }

        // Only add if not already referencing
        if (!in_array($toObjectId, $this->objects[$fromObjectId]['references'], true)) {
            $this->objects[$fromObjectId]['references'][] = $toObjectId;
            $this->increaseRefCount($toObjectId);
        }
    }

    /**
     * Remove a reference from one object to another, decrementing target's refcount.
     *
     * @param int $fromObjectId
     * @param int $toObjectId
     */
    public function removeReference(int $fromObjectId, int $toObjectId): void
    {
        if (!isset($this->objects[$fromObjectId])) {
            echo "Cannot remove reference: invalid 'from' object ID.\n";
            return;
        }

        $index = array_search($toObjectId, $this->objects[$fromObjectId]['references'], true);
        if ($index !== false) {
            // Remove from references, decrement refcount
            array_splice($this->objects[$fromObjectId]['references'], $index, 1);
            $this->decreaseRefCount($toObjectId);
        }
    }

    /**
     * Removes an object from the root set (meaning the user "lets go" of it externally).
     *
     * @param int $objectId
     */
    public function removeRootReference(int $objectId): void
    {
        $idx = array_search($objectId, $this->rootReferences, true);
        if ($idx !== false) {
            unset($this->rootReferences[$idx]);
            $this->decreaseRefCount($objectId);
        }
    }

    /**
     * Simulate a memory leak by creating two objects with circular references.
     *
     * After creation, they are removed from root references so they are only referencing each other,
     * thus forming a cycle that normal reference counting alone cannot free (unless manually detected as a leak).
     */
    public function simulateLeak(): void
    {
        $objId1 = $this->createObject("LeakObjectA", true);
        $objId2 = $this->createObject("LeakObjectB", true);

        $this->addReference($objId1, $objId2);
        $this->addReference($objId2, $objId1);

        // Remove both from root references, so they survive only by referencing each other
        $this->removeRootReference($objId1);
        $this->removeRootReference($objId2);

        echo "Simulated a leak between Object #$objId1 and Object #$objId2.\n";
        echo "Both are removed from the root set, so they remain only by mutual reference.\n";
    }

    /**
     * Detect objects that are not reachable from the root references (i.e., orphaned).
     * Objects that are orphaned but still have a non-zero refcount are considered "leaking."
     *
     * @return array<int> List of object IDs that are leaking (orphaned).
     */
    public function detectLeaks(): array
    {
        $reachable = $this->findAllReachableFromRoots();

        $leaks = [];
        foreach ($this->objects as $id => $obj) {
            if (!in_array($id, $reachable, true)) {
                // This object is not reachable from any root -> leak
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    /**
     * Performs a breadth-first search from all root objects to find reachable ones.
     */
    private function findAllReachableFromRoots(): array
    {
        $visited = [];
        $queue   = array_values($this->rootReferences);

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (in_array($current, $visited, true)) {
                continue;
            }
            $visited[] = $current;

            // Enqueue children references
            if (!empty($this->objects[$current]['references'])) {
                foreach ($this->objects[$current]['references'] as $refId) {
                    if (!in_array($refId, $visited, true)) {
                        $queue[] = $refId;
                    }
                }
            }
        }
        return $visited;
    }

    /**
     * Run a cleanup cycle that forcibly frees any object not reachable from the root set.
     * This simulates detecting a memory leak and cleaning it up manually.
     */
    public function cleanupOrphans(): void
    {
        $leaks = $this->detectLeaks();
        if (empty($leaks)) {
            echo "No orphaned objects detected. Nothing to clean up.\n";
            return;
        }

        echo "Cleaning up orphaned objects: " . implode(', ', $leaks) . "\n";
        // We forcibly free them
        foreach ($leaks as $id) {
            $this->freeObject($id);
        }
    }

    /**
     * Display a summary of the current state of the system:
     *  - Number of objects
     *  - Memory usage
     *  - For each object, show ID, reference count, references
     *  - Show root references
     */
    public function showStatus(): void
    {
        $objectCount = count($this->objects);
        echo "---- STATUS REPORT ----\n";
        echo "Total Objects: " . $objectCount . "\n";
        echo "Root References: [" . implode(', ', $this->rootReferences) . "]\n";
        echo "PHP Memory Usage (approx): " . number_format(memory_get_usage(true) / 1024, 2) . " KB\n";

        if ($objectCount > 0) {
            echo "Object Details:\n";
            foreach ($this->objects as $id => $obj) {
                $rc   = $this->refCounts[$id] ?? 0;
                $refs = implode(', ', $obj['references']);
                echo "  #$id [refcount=$rc] => references: [$refs]\n";
            }
        }
        echo "-----------------------\n";
    }
}

/**
 * Simple CLI interface to interact with the ObjectManager.
 */
function runCli()
{
    $manager = new ObjectManager();

    echo "Memory Leak Simulator\n";
    echo "Type 'help' for a list of commands.\n";

    while (true) {
        echo "\n> ";
        $input = trim(fgets(STDIN));
        if ($input === false) {
            break; // End of input
        }
        $parts = explode(' ', $input);
        $cmd   = strtolower($parts[0] ?? '');

        switch ($cmd) {
            case 'help':
                echo "Available commands:\n";
                echo "  create [count]   - Create one or more objects (added to root references)\n";
                echo "  leak             - Simulate a circular reference leak\n";
                echo "  reference a b    - Make object #a reference object #b\n";
                echo "  unreference a b  - Remove reference from object #a to object #b\n";
                echo "  removeroot x     - Remove object #x from the root set\n";
                echo "  show             - Display current status\n";
                echo "  detect           - Detect orphaned (leaking) objects\n";
                echo "  gc               - Clean up orphaned objects\n";
                echo "  exit             - Quit the simulator\n";
                break;

            case 'create':
                $count = isset($parts[1]) ? (int)$parts[1] : 1;
                for ($i = 0; $i < $count; $i++) {
                    $id = $manager->createObject("DataObject");
                    echo "Created object #$id\n";
                }
                break;

            case 'leak':
                $manager->simulateLeak();
                break;

            case 'reference':
                if (!isset($parts[1], $parts[2])) {
                    echo "Usage: reference <fromId> <toId>\n";
                    break;
                }
                $manager->addReference((int)$parts[1], (int)$parts[2]);
                break;

            case 'unreference':
                if (!isset($parts[1], $parts[2])) {
                    echo "Usage: unreference <fromId> <toId>\n";
                    break;
                }
                $manager->removeReference((int)$parts[1], (int)$parts[2]);
                break;

            case 'removeroot':
                if (!isset($parts[1])) {
                    echo "Usage: removeroot <objectId>\n";
                    break;
                }
                $manager->removeRootReference((int)$parts[1]);
                break;

            case 'show':
                $manager->showStatus();
                break;

            case 'detect':
                $leaks = $manager->detectLeaks();
                if (empty($leaks)) {
                    echo "No memory leaks detected.\n";
                } else {
                    echo "Detected memory leaks in object(s): " . implode(', ', $leaks) . "\n";
                }
                break;

            case 'gc':
                $manager->cleanupOrphans();
                break;

            case 'exit':
                echo "Exiting...\n";
                return;

            default:
                echo "Unknown command. Type 'help' for available commands.\n";
        }
    }
}

// Run the CLI interface
runCli();