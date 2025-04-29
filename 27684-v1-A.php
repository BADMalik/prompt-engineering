<?php

/**
 * Memory Leak Simulator and Resolver
 *
 * This standalone PHP script simulates a memory leak by creating circular
 * references between objects, manually tracks reference counts, detects leaks,
 * and applies reference counting strategies to resolve them. It also provides
 * a CLI interface for interacting with the system.
 *
 * Usage:
 *   php memory_leak_simulator.php
 *
 * Author: ChatGPT
 * Version: 1.0
 */

/**
 * Class LeakyObject
 *
 * Represents an object that holds arbitrary data and may reference other objects,
 * potentially creating circular references. Each LeakyObject has a manually
 * maintained reference count.
 */
class LeakyObject
{
    private static int $globalIdCounter = 1;

    private int $id;
    private mixed $data;
    private array $references = []; // Holds references to other LeakyObject instances
    private int $refCount = 0;      // Reference count for manual garbage collection

    public function __construct(mixed $data = null)
    {
        $this->id   = self::$globalIdCounter++;
        $this->data = $data;
        $this->refCount = 1; // Start with 1 because once created, we have a handle to it.

        // Register with global ReferenceManager
        ReferenceManager::getInstance()->registerObject($this);
    }

    /**
     * Returns the unique ID of this object.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Increments the reference count for this object.
     */
    public function incrementRef(): void
    {
        $this->refCount++;
    }

    /**
     * Decrements the reference count for this object.
     * If refCount hits zero, the object can be destroyed.
     */
    public function decrementRef(): void
    {
        $this->refCount--;
        if ($this->refCount <= 0) {
            $this->destroy();
        }
    }

    /**
     * Returns the current reference count.
     */
    public function getRefCount(): int
    {
        return $this->refCount;
    }

    /**
     * Adds a reference to another LeakyObject.
     */
    public function addReference(LeakyObject $obj): void
    {
        // If not already referencing
        if (!in_array($obj, $this->references, true)) {
            $this->references[] = $obj;
            $obj->incrementRef();
        }
    }

    /**
     * Removes a reference to another LeakyObject.
     */
    public function removeReference(LeakyObject $obj): void
    {
        foreach ($this->references as $index => $reference) {
            if ($reference === $obj) {
                unset($this->references[$index]);
                $this->references = array_values($this->references);
                $obj->decrementRef();
                break;
            }
        }
    }

    /**
     * Returns an array of referenced objects (for inspection).
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    /**
     * Returns arbitrary data stored in this object.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Simulates destruction and notifies the ReferenceManager.
     */
    private function destroy(): void
    {
        // Before final destruction, break references to avoid lingering references
        foreach ($this->references as $reference) {
            $reference->decrementRef();
        }
        $this->references = [];

        // Unregister from the manager
        ReferenceManager::getInstance()->unregisterObject($this);

        // (Object is effectively destroyed here; in real scenario, PHP won't
        // actually free memory until this object is out of scope, but we simulate
        // that by removing it from the manager.)
    }
}

/**
 * Class ReferenceManager
 *
 * Tracks all created LeakyObject instances, their reference counts,
 * and provides a mechanism to detect memory leaks (dangling circular references).
 */
class ReferenceManager
{
    private static ?ReferenceManager $instance = null;

    /** @var array<int, LeakyObject> $objects An associative list of all active objects, keyed by object ID. */
    private array $objects = [];

    /**
     * Singleton getInstance.
     */
    public static function getInstance(): ReferenceManager
    {
        if (self::$instance === null) {
            self::$instance = new ReferenceManager();
        }
        return self::$instance;
    }

    /**
     * Registers a new LeakyObject.
     */
    public function registerObject(LeakyObject $obj): void
    {
        $this->objects[$obj->getId()] = $obj;
    }

    /**
     * Unregisters a LeakyObject when it is destroyed.
     */
    public function unregisterObject(LeakyObject $obj): void
    {
        unset($this->objects[$obj->getId()]);
    }

    /**
     * Returns an array of currently tracked objects.
     *
     * @return LeakyObject[]
     */
    public function getObjects(): array
    {
        return $this->objects;
    }

    /**
     * Simulated garbage collector:
     * We attempt to find objects with refCount <= 0 (which should auto-destroy).
     * Then we also detect any "leaked" objects that have no external references,
     * but are referencing each other circularly.
     */
    public function collectGarbage(): void
    {
        // 1. Clean up objects with refCount <= 0
        foreach ($this->objects as $id => $obj) {
            if ($obj->getRefCount() <= 0) {
                // This destroy will also unregister it
                $obj->decrementRef();
            }
        }

        // 2. Attempt to detect "orphaned" objects, i.e. objects that are not
        // reachable from any "root" reference. In plain PHP, a "root" might
        // be something like a global or a method-local variable that we can reach.
        // For simplicity, we'll consider all objects to be "roots" if they are
        // explicitly known by the manager. That means if an object is only known
        // by a circular reference with no external pointer, we try to handle it.
        // This is a simulated approach – real GC is more complex.
        //
        // Pseudocode approach:
        //  - We pick each object as a root if it is in $this->objects
        //  - We do a graph traversal marking visited objects
        //  - Any objects not visited after the traversal are "orphaned"

        $visited = [];
        foreach ($this->objects as $obj) {
            $this->markReachable($obj, $visited);
        }

        // Objects not marked as visited are considered leaked
        foreach ($this->objects as $id => $obj) {
            if (!in_array($id, $visited, true)) {
                echo "Leak detected: Object #{$obj->getId()} is orphaned (circular reference suspected)\n";
                // Attempt a manual break of references to free them
                // In a real scenario, the GC might do deeper analysis.
                $this->breakCircularReference($obj);
            }
        }

        // Cleanup might free up some references after we attempt to break circular references
        foreach ($this->objects as $id => $obj) {
            if ($obj->getRefCount() <= 0) {
                $obj->decrementRef();
            }
        }
    }

    /**
     * Recursively marks reachable objects from the given root.
     */
    private function markReachable(LeakyObject $obj, array &$visited): void
    {
        if (in_array($obj->getId(), $visited, true)) {
            return;
        }
        $visited[] = $obj->getId();
        foreach ($obj->getReferences() as $child) {
            $this->markReachable($child, $visited);
        }
    }

    /**
     * Attempts to break circular references in an orphaned object by removing
     * references altogether (thus forcing refCount to drop to zero).
     */
    private function breakCircularReference(LeakyObject $obj): void
    {
        foreach ($obj->getReferences() as $ref) {
            $obj->removeReference($ref);
        }
    }
}

/**
 * Class CLI
 *
 * Simple CLI interface for interacting with the memory leak simulator.
 */
class CLI
{
    public static function run()
    {
        echo "=============================================\n";
        echo "     Memory Leak Simulator (Reference RC)    \n";
        echo "=============================================\n\n";

        $manager = ReferenceManager::getInstance();

        while (true) {
            self::printMenu();
            $choice = trim(fgets(STDIN));

            switch ($choice) {
                case '1':
                    // Create new object
                    echo "Enter optional data for the object (or press Enter): ";
                    $data = trim(fgets(STDIN));
                    $obj = new LeakyObject($data ?: null);
                    echo "Created object #{$obj->getId()} with refCount={$obj->getRefCount()}.\n";
                    break;

                case '2':
                    // Link objects
                    $objects = $manager->getObjects();
                    if (empty($objects)) {
                        echo "No objects to link. Create objects first!\n";
                        break;
                    }
                    self::listObjects();
                    echo "Enter the ID of the source object to reference from: ";
                    $srcId = (int) trim(fgets(STDIN));
                    echo "Enter the ID of the target object to be referenced: ";
                    $dstId = (int) trim(fgets(STDIN));
                    if (isset($objects[$srcId]) && isset($objects[$dstId])) {
                        if ($srcId === $dstId) {
                            echo "Cannot reference the same object as source and target (self-reference not supported in this demo).\n";
                            break;
                        }
                        $objects[$srcId]->addReference($objects[$dstId]);
                        echo "Object #{$srcId} now references #{$dstId}.\n";
                    } else {
                        echo "Invalid IDs provided.\n";
                    }
                    break;

                case '3':
                    // Unlink objects
                    $objects = $manager->getObjects();
                    if (empty($objects)) {
                        echo "No objects to unlink. Create objects first!\n";
                        break;
                    }
                    self::listObjects();
                    echo "Enter the ID of the source object: ";
                    $srcId = (int) trim(fgets(STDIN));
                    echo "Enter the ID of the target object to remove reference to: ";
                    $dstId = (int) trim(fgets(STDIN));
                    if (isset($objects[$srcId]) && isset($objects[$dstId])) {
                        $objects[$srcId]->removeReference($objects[$dstId]);
                        echo "Object #{$srcId} no longer references #{$dstId}.\n";
                    } else {
                        echo "Invalid IDs provided.\n";
                    }
                    break;

                case '4':
                    // Show stats
                    self::listObjects();
                    break;

                case '5':
                    // Manual GC
                    echo "Running manual garbage collection...\n";
                    $manager->collectGarbage();
                    echo "Garbage collection completed.\n";
                    break;

                case '6':
                    // Exit
                    echo "Exiting simulator. Goodbye!\n";
                    exit(0);

                default:
                    echo "Invalid option. Please retry.\n";
                    break;
            }

            echo "\n";
        }
    }

    private static function printMenu()
    {
        echo "Menu:\n";
        echo "  1) Create a new object\n";
        echo "  2) Link objects (create reference)\n";
        echo "  3) Unlink objects (remove reference)\n";
        echo "  4) Show object stats\n";
        echo "  5) Force garbage collection\n";
        echo "  6) Exit\n";
        echo "Choose an option (1-6): ";
    }

    /**
     * Prints out each tracked object with its ID, reference count,
     * references, and data.
     */
    private static function listObjects()
    {
        $objects = ReferenceManager::getInstance()->getObjects();

        if (empty($objects)) {
            echo "No objects currently exist.\n";
            return;
        }

        echo "\nActive Objects:\n";
        foreach ($objects as $obj) {
            $refIds = [];
            foreach ($obj->getReferences() as $refObj) {
                $refIds[] = $refObj->getId();
            }
            $refList = empty($refIds) ? "None" : implode(", ", $refIds);
            $data    = $obj->getData() ?: 'NULL';
            echo " - Object #{$obj->getId()}: refCount={$obj->getRefCount()}, references=[{$refList}], data=\"{$data}\"\n";
        }
        echo "\n";
    }
}

// Run the CLI
CLI::run();
