<?php

// Class to simulate memory leak and track reference count
class LeakSimulator
{
    private static $objectCount = 0;
    private $id;
    private $refCount;

    public function __construct()
    {
        $this->id = ++self::$objectCount;
        $this->refCount = 0;
        echo "Created object #$this->id\n";
    }

    // Simulate object reference increment
    public function incrementReference()
    {
        $this->refCount++;
    }

    // Simulate object reference decrement
    public function decrementReference()
    {
        $this->refCount--;
    }

    // Get the current reference count
    public function getReferenceCount()
    {
        return $this->refCount;
    }

    // Get object id
    public function getId()
    {
        return $this->id;
    }

    // Cleanup - print message when refCount is 0
    public function cleanup()
    {
        if ($this->refCount === 0) {
            echo "Object #$this->id has no references and can be cleaned up.\n";
        }
    }
}

// Function to simulate a memory leak by creating circular references
function createMemoryLeak()
{
    $objectA = new LeakSimulator();
    $objectB = new LeakSimulator();

    // Creating circular reference
    $objectA->incrementReference();
    $objectB->incrementReference();

    $objectA->decrementReference(); // Remove one reference
    $objectB->decrementReference(); // Remove one reference

    return [$objectA, $objectB]; // Objects should still be referenced, leading to a memory leak
}

// Function to track and detect memory leaks
function detectMemoryLeaks($objects)
{
    echo "\nChecking for memory leaks...\n";

    foreach ($objects as $obj) {
        $refCount = $obj->getReferenceCount();
        echo "Object #" . $obj->getId() . " has reference count: $refCount\n";

        // Detect if reference count is greater than 0
        if ($refCount > 0) {
            echo "Object #" . $obj->getId() . " is still in memory.\n";
        }
    }
}

// Simulate garbage collection or cleanup
function cleanupMemory($objects)
{
    echo "\nTriggering cleanup...\n";
    foreach ($objects as $obj) {
        $obj->cleanup(); // Will print message if refCount reaches zero
    }
}

// Test Case 1: Basic Leak Detection
function testMemoryLeakDetection()
{
    echo "Running Test Case: Basic Leak Detection\n";
    $leakedObjects = createMemoryLeak(); // Simulate memory leak
    detectMemoryLeaks($leakedObjects);  // Detect memory leaks
    cleanupMemory($leakedObjects);     // Cleanup memory after leaks
}

// Test Case 2: Object with no references (cleanup should trigger)
function testObjectCleanup()
{
    echo "Running Test Case: Object Cleanup\n";
    $obj = new LeakSimulator();
    $obj->decrementReference();  // Object should have no references now
    cleanupMemory([$obj]);  // Cleanup should be triggered
}

// Test Case 3: Multiple Circular References
function testMultipleCircularReferences()
{
    echo "Running Test Case: Multiple Circular References\n";
    $obj1 = new LeakSimulator();
    $obj2 = new LeakSimulator();
    $obj3 = new LeakSimulator();

    // Creating circular references between three objects
    $obj1->incrementReference();
    $obj2->incrementReference();
    $obj3->incrementReference();

    $obj1->decrementReference();
    $obj2->decrementReference();
    $obj3->decrementReference();

    detectMemoryLeaks([$obj1, $obj2, $obj3]);
    cleanupMemory([$obj1, $obj2, $obj3]);
}

// Test Case 4: Edge Case - No Leaks (All references cleared)
function testNoLeaks()
{
    echo "Running Test Case: No Leaks\n";
    $obj1 = new LeakSimulator();
    $obj2 = new LeakSimulator();

    $obj1->incrementReference();
    $obj2->incrementReference();

    $obj1->decrementReference();
    $obj2->decrementReference();

    cleanupMemory([$obj1, $obj2]); // Should clean up as refCount reaches zero
}

// Main function to execute tests
function runTests()
{
    testMemoryLeakDetection();  // Test memory leak scenario
    testObjectCleanup();        // Test cleanup when no references exist
    testMultipleCircularReferences();  // Test circular references with multiple objects
    testNoLeaks();  // Test when there are no leaks
}

// Execute tests
runTests();
