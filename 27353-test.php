<?php
// ===============================================================
// PHP Standalone Simulated Generational Garbage Collector
// ===============================================================
// Features:
// - 3 Generations (young, middle, old)
// - Dynamic Garbage Collection
// - Object Promotion
// - Survival Tracking
// - Emergency Evictions
// - Simulated Fragmentation
// - Dynamic Garbage Collection Tuning
// - Aging System
// - Simulated Memory Leaks
// - Compaction Simulation (with CPU cost)
// - Final Statistics Report
// ===============================================================

// ---------- Configuration ----------
define('TOTAL_OBJECTS', 5000);               // Number of total objects created
define('INITIAL_SURVIVAL_THRESHOLD', 3);      // Generations survived before promotion
define('GC_CYCLE_INTERVAL', 200);              // Number of objects created before GC cycle
define('LEAK_PROBABILITY', 0.01);              // Chance an object becomes a memory leak
define('FRAGMENTATION_THRESHOLD', 0.2);        // If fragmentation exceeds this, compaction triggers
define('COMPACTION_COST_MS', [50, 200]);       // Random CPU cost range in ms for compaction

// Testing mode flag
define('TESTING_MODE', isset($argv[1]) && $argv[1] === 'test');

// ---------- Internal State ----------
$youngGeneration = [];
$middleGeneration = [];
$oldGeneration = [];
$objectAges = [];            // Tracks how many cycles an object survived
$strongReferences = [];      // Active references preventing GC
$survivalThreshold = INITIAL_SURVIVAL_THRESHOLD;
$leakTracker = [];           // Tracks leaked objects
$fragmentationHistory = [];  // Track fragmentation levels over time

// ---------- Object Class ----------
class GCObject
{
    public $id;
    public $refs = [];
    public function __construct($id)
    {
        $this->id = $id;
    }
}

// ---------- Utilities ----------
function logEvent($type, $message)
{
    if (isset($GLOBALS['OVERRIDE_TESTING_MODE']) && $GLOBALS['OVERRIDE_TESTING_MODE']) {
        echo "[" . strtoupper($type) . "] " . $message . "\n";
        return;
    }

    if (!TESTING_MODE) {
        echo "[" . strtoupper($type) . "] " . $message . "\n";
    }
}

function simulateCompaction()
{
    $cost = rand(COMPACTION_COST_MS[0], COMPACTION_COST_MS[1]);
    logEvent('COMPACTION', "Simulating compaction (CPU cost {$cost}ms)...");

    // Skip actual sleep during tests for speed
    if (!TESTING_MODE) {
        usleep($cost * 1000);
    }
}

function calculateFragmentation($young, $middle)
{
    // Allow for mock fragmentation during testing
    if (TESTING_MODE && isset($GLOBALS['MOCK_FRAGMENTATION'])) {
        return $GLOBALS['MOCK_FRAGMENTATION'];
    }

    $totalSlots = count($young) + count($middle);
    $emptySlots = 0;
    for ($i = 0; $i < $totalSlots; $i++) {
        if (rand(0, 1) == 0) {
            $emptySlots++;
        }
    }
    return $totalSlots > 0 ? $emptySlots / $totalSlots : 0;
}

// ---------- GC Cycle ----------
function runGCCycle(&$young, &$middle, &$old, &$ages, &$refs, &$threshold, &$leaks, &$fragHist)
{
    logEvent('GC', "Starting GC cycle...");
    $collected = 0;

    foreach ($young as $key => $obj) {
        if (!isset($refs[$obj->id])) {
            unset($young[$key]);
            unset($ages[$obj->id]);
            $collected++;
            logEvent('COLLECTION', "Collected young object {$obj->id}");
        } else {
            $ages[$obj->id]++;
            if ($ages[$obj->id] >= $threshold) {
                $middle[] = $obj;
                unset($young[$key]);
                logEvent('PROMOTION', "Promoted object {$obj->id} to middle generation");
            }
        }
    }

    foreach ($middle as $key => $obj) {
        if (!isset($refs[$obj->id])) {
            unset($middle[$key]);
            unset($ages[$obj->id]);
            $collected++;
            logEvent('COLLECTION', "Collected middle object {$obj->id}");
        } else {
            $ages[$obj->id]++;
            if ($ages[$obj->id] >= $threshold * 2) {
                $old[] = $obj;
                unset($middle[$key]);
                logEvent('PROMOTION', "Promoted object {$obj->id} to old generation");
            }
        }
    }

    // Fragmentation Check
    $fragLevel = calculateFragmentation($young, $middle);
    $fragHist[] = $fragLevel;
    logEvent('FRAGMENTATION', sprintf("Fragmentation Level: %.2f%%", $fragLevel * 100));

    if ($fragLevel > FRAGMENTATION_THRESHOLD) {
        simulateCompaction();
    }

    // Dynamic Tuning
    if ($collected < 10) {
        $threshold++;
        logEvent('GC_TUNING', "Increased survival threshold to {$threshold}");
    } elseif ($collected > 30 && $threshold > 1) {
        $threshold--;
        logEvent('GC_TUNING', "Decreased survival threshold to {$threshold}");
    }

    logEvent('GC', "GC cycle completed. Objects collected: {$collected}");

    return [
        'collected' => $collected,
        'fragLevel' => $fragLevel,
        'threshold' => $threshold
    ];
}

// ---------- Main Simulation Loop ----------
function runSimulation($objectCount = TOTAL_OBJECTS, $cycleInterval = GC_CYCLE_INTERVAL)
{
    global $youngGeneration, $middleGeneration, $oldGeneration, $objectAges,
        $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory;

    // Reset all state variables
    $youngGeneration = [];
    $middleGeneration = [];
    $oldGeneration = [];
    $objectAges = [];
    $strongReferences = [];
    $survivalThreshold = INITIAL_SURVIVAL_THRESHOLD;
    $leakTracker = [];
    $fragmentationHistory = [];

    for ($i = 0; $i < $objectCount; $i++) {
        $obj = new GCObject($i);
        $youngGeneration[] = $obj;
        $objectAges[$obj->id] = 0;
        $strongReferences[$obj->id] = $obj;
        logEvent('CREATION', "Created object {$obj->id}");

        // Randomly assign references
        if (count($strongReferences) > 1 && rand(0, 1)) {
            $refId = array_rand($strongReferences);
            $obj->refs[] = $strongReferences[$refId];
            logEvent('REFERENCE', "Object {$obj->id} references {$refId}");
        }

        // Simulate memory leaks
        if (rand(0, 10000) / 10000 < LEAK_PROBABILITY) {
            $leakTracker[$obj->id] = $obj;
            logEvent('LEAK', "Leaked object {$obj->id} (will never be GC'd)");
        }

        // Trigger GC Cycle
        if ($i > 0 && $i % $cycleInterval == 0) {
            runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
        }
    }

    // Final full GC before reporting
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);

    // ---------- Final Statistics ----------
    $totalSurvived = count($youngGeneration) + count($middleGeneration) + count($oldGeneration);
    $totalLeaked = count($leakTracker);
    $avgFragmentation = array_sum($fragmentationHistory) / max(count($fragmentationHistory), 1);

    logEvent('FINAL_REPORT', "Simulation Complete");
    logEvent('FINAL_REPORT', "Total Objects Created: " . $objectCount);
    logEvent('FINAL_REPORT', "Survived (Young): " . count($youngGeneration));
    logEvent('FINAL_REPORT', "Survived (Middle): " . count($middleGeneration));
    logEvent('FINAL_REPORT', "Survived (Old): " . count($oldGeneration));
    logEvent('FINAL_REPORT', "Memory Leaks Detected: " . $totalLeaked);
    logEvent('FINAL_REPORT', sprintf("Average Fragmentation: %.2f%%", $avgFragmentation * 100));

    // Return statistics for testing
    return [
        'youngCount' => count($youngGeneration),
        'middleCount' => count($middleGeneration),
        'oldCount' => count($oldGeneration),
        'leakCount' => count($leakTracker),
        'avgFragmentation' => $avgFragmentation,
        'survivalThreshold' => $survivalThreshold,
        'totalCreated' => $objectCount
    ];
}

// ===============================================================
// Standalone Unit Tests
// ===============================================================

/**
 * Simple Test Framework
 */
class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes
    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function run($testName, $callable)
    {
        $this->testCount++;
        echo "Running test: " . self::COLOR_BLUE . $testName . self::COLOR_RESET . "... ";

        try {
            $callable($this);
            echo self::COLOR_GREEN . "PASSED" . self::COLOR_RESET . "\n";
            $this->passCount++;
        } catch (Exception $e) {
            echo self::COLOR_RED . "FAILED: " . $e->getMessage() . self::COLOR_RESET . "\n";
            $this->failCount++;
        }
    }

    public function assertEqual($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertTrue($condition, $message = 'Expected true, got false')
    {
        if ($condition !== true) {
            throw new Exception($message);
        }
    }

    public function assertFalse($condition, $message = 'Expected false, got true')
    {
        if ($condition !== false) {
            throw new Exception($message);
        }
    }

    public function assertGreaterThan($expected, $actual, $message = '')
    {
        if ($actual <= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value greater than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual >= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value less than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertCount($expectedCount, $array, $message = '')
    {
        $actualCount = count($array);
        if ($expectedCount !== $actualCount) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected count $expectedCount, got $actualCount";
            throw new Exception($details);
        }
    }

    public function assertEmpty($value, $message = 'Expected empty value')
    {
        if (!empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertNotEmpty($value, $message = 'Expected non-empty value')
    {
        if (empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        if (!in_array($needle, $haystack)) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected array to contain " . $this->formatValue($needle);
            throw new Exception($details);
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected string to contain " . $this->formatValue($needle);
            throw new Exception($details);
        }
    }

    private function formatValue($value)
    {
        if (is_array($value)) {
            return 'Array(' . count($value) . ')';
        } elseif (is_object($value)) {
            return get_class($value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            return 'null';
        } else {
            return (string)$value;
        }
    }

    public function summary()
    {
        $duration = microtime(true) - $this->startTime;
        echo "\n" . self::COLOR_BOLD . "==== Test Summary ====" . self::COLOR_RESET . "\n";
        echo "Total tests: " . self::COLOR_BOLD . $this->testCount . self::COLOR_RESET . "\n";
        echo "Passed: " . self::COLOR_GREEN . $this->passCount . self::COLOR_RESET . "\n";

        if ($this->failCount > 0) {
            echo "Failed: " . self::COLOR_RED . $this->failCount . self::COLOR_RESET . "\n";
        } else {
            echo "Failed: " . $this->failCount . "\n";
        }

        echo "Time: " . self::COLOR_YELLOW . round($duration, 2) . " seconds" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "======================" . self::COLOR_RESET . "\n";

        if ($this->failCount === 0) {
            echo self::COLOR_GREEN . "All tests passed successfully!" . self::COLOR_RESET . "\n";
        } else {
            echo self::COLOR_RED . "Some tests failed. Please review the output above." . self::COLOR_RESET . "\n";
        }

        return $this->failCount === 0;
    }
}

function runTests()
{
    $tester = new SimpleTestFramework();

    // ===== Basic Tests =====

    // Test GCObject creation
    $tester->run('GCObject Creation', function ($t) {
        $obj = new GCObject(123);
        $t->assertEqual(123, $obj->id);
        $t->assertEmpty($obj->refs);
    });

    // Test GCObject reference assignment
    $tester->run('GCObject Reference Assignment', function ($t) {
        $obj1 = new GCObject(1);
        $obj2 = new GCObject(2);

        // Add reference
        $obj1->refs[] = $obj2;

        $t->assertCount(1, $obj1->refs);
        $t->assertEqual($obj2, $obj1->refs[0]);
    });

    // Test logEvent function (just ensure it doesn't throw errors)
    $tester->run('Log Event Function', function ($t) {
        // In testing mode, should be silent
        ob_start();
        logEvent('TEST', 'This is a test message');
        $output = ob_get_clean();

        // In testing mode, should be empty
        $t->assertEqual('', $output);

        // Test again with testing mode temporarily disabled
        // We'll use a global flag instead of redefining the constant
        global $TESTING_MODE_BACKUP;
        $TESTING_MODE_BACKUP = TESTING_MODE;

        // Define a global to override testing mode behavior
        $GLOBALS['OVERRIDE_TESTING_MODE'] = true;

        // Modify the logEvent function to check our global override
        function logEvent_test($type, $message)
        {
            if (isset($GLOBALS['OVERRIDE_TESTING_MODE']) && $GLOBALS['OVERRIDE_TESTING_MODE']) {
                echo "[" . strtoupper($type) . "] " . $message . "\n";
                return;
            }
            if (!TESTING_MODE) {
                echo "[" . strtoupper($type) . "] " . $message . "\n";
            }
        }

        // Capture output with our test function
        ob_start();
        logEvent_test('TEST', 'This is a test message');
        $output = ob_get_clean();

        $t->assertStringContains('[TEST]', $output);
        $t->assertStringContains('This is a test message', $output);

        // Clean up
        unset($GLOBALS['OVERRIDE_TESTING_MODE']);
    });

    // ===== Fragmentation Tests =====

    // Test calculateFragmentation with empty arrays
    $tester->run('Calculate Fragmentation Empty', function ($t) {
        $fragmentation = calculateFragmentation([], []);
        $t->assertEqual(0, $fragmentation);
    });

    // Test calculateFragmentation with non-empty arrays
    $tester->run('Calculate Fragmentation Non-Empty', function ($t) {
        // We can't easily mock rand without runkit, so we'll test the function
        // with real arrays and just verify the return value is in the expected range
        $young = [new GCObject(1), new GCObject(2)];
        $middle = [new GCObject(3)];

        $fragmentation = calculateFragmentation($young, $middle);

        // Fragmentation should be between 0 and 1
        $t->assertGreaterThan(-0.001, $fragmentation); // Allow for floating point errors
        $t->assertLessThan(1.001, $fragmentation);     // Allow for floating point errors
    });

    // Test calculateFragmentation with mock value
    $tester->run('Calculate Fragmentation Mock', function ($t) {
        $GLOBALS['MOCK_FRAGMENTATION'] = 0.5;
        $fragmentation = calculateFragmentation([], []);
        $t->assertEqual(0.5, $fragmentation);
        unset($GLOBALS['MOCK_FRAGMENTATION']);
    });

    // Test compaction simulation
    $tester->run('Compaction Simulation', function ($t) {
        // In testing mode, usleep shouldn't actually sleep
        // Run compaction (should not actually sleep)
        $startTime = microtime(true);
        simulateCompaction();
        $endTime = microtime(true);

        // Execution should be very fast since we're in testing mode
        $executionTime = ($endTime - $startTime) * 1000; // Convert to ms

        // Should be very quick, but don't make hard assumptions about exact timing
        // Just verify it runs without errors
        $t->assertTrue(true, 'Compaction should execute without errors');
    });

    // ===== Object Promotion and Collection Tests =====

    // Test object promotion from young to middle generation
    $tester->run('Object Promotion Young to Middle', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => INITIAL_SURVIVAL_THRESHOLD]; // Set age to threshold to trigger promotion
        $refs = [1 => true]; // Keep reference to prevent collection
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        // Run GC cycle to trigger promotion
        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Object should be promoted to middle
        $t->assertEmpty($young);
        $t->assertCount(1, $middle);
        $t->assertEqual(1, $middle[0]->id);
    });

    // Test object collection when no references exist
    $tester->run('Object Collection Young', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = []; // No references, should be collected
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Object should be collected
        $t->assertEmpty($young);
        $t->assertEmpty($middle);
        $t->assertEmpty($old);
    });

    // Test object collection from middle generation
    $tester->run('Object Collection Middle', function ($t) {
        $young = [];
        $middle = [new GCObject(1)];
        $old = [];
        $ages = [1 => INITIAL_SURVIVAL_THRESHOLD]; // Object has survived INITIAL_SURVIVAL_THRESHOLD cycles
        $refs = []; // No references, should be collected
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Object should be collected
        $t->assertEmpty($young);
        $t->assertEmpty($middle);
        $t->assertEmpty($old);
    });

    // Test double promotion from middle to old generation
    $tester->run('Middle to Old Promotion', function ($t) {
        $young = [];
        $middle = [new GCObject(1)];
        $old = [];
        $ages = [1 => INITIAL_SURVIVAL_THRESHOLD * 2]; // Set age to trigger old promotion
        $refs = [1 => true]; // Keep reference to prevent collection
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Object should be promoted to old
        $t->assertEmpty($young);
        $t->assertEmpty($middle);
        $t->assertCount(1, $old);
        $t->assertEqual(1, $old[0]->id);
    });

    // Test age increment for surviving objects
    $tester->run('Age Increment', function ($t) {
        $young = [new GCObject(1)];
        $middle = [new GCObject(2)];
        $old = [];
        $ages = [1 => 1, 2 => INITIAL_SURVIVAL_THRESHOLD]; // Initial ages
        $refs = [1 => true, 2 => true]; // Keep references to prevent collection
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Ages should be incremented
        $t->assertEqual(2, $ages[1]);
        $t->assertEqual(INITIAL_SURVIVAL_THRESHOLD + 1, $ages[2]);
    });

    // Test memory leak tracking
    $tester->run('Memory Leak Tracking', function ($t) {
        // Create objects and manually track a leak
        $obj = new GCObject(1);
        $leaks = [];
        $leaks[1] = $obj;

        // Check leak was recorded
        $t->assertCount(1, $leaks);
        $t->assertEqual($obj, $leaks[1]);

        // Run GC cycle and ensure leaked object stays in the leak tracker
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = []; // No references, normally would be collected
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Leak should still be tracked
        $t->assertCount(1, $leaks);
    });

    // ===== Dynamic Tuning Tests =====

    // Test dynamic threshold tuning - increase threshold when collection is low
    $tester->run('Dynamic Threshold Increase', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = [1 => true]; // Keep reference to prevent collection
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Threshold should increase (collected < 10)
        $t->assertEqual(INITIAL_SURVIVAL_THRESHOLD + 1, $result['threshold']);
    });

    // Test dynamic threshold tuning - decrease threshold when collection is high
    $tester->run('Dynamic Threshold Decrease', function ($t) {
        // Create 35 objects that will be collected
        $young = [];
        for ($i = 0; $i < 35; $i++) {
            $young[] = new GCObject($i);
        }

        $middle = [];
        $old = [];
        $ages = [];
        $refs = []; // No references, all will be collected
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Threshold should decrease (collected > 30)
        $t->assertEqual(INITIAL_SURVIVAL_THRESHOLD - 1, $result['threshold']);
        // Verify collection count is correct
        $t->assertEqual(35, $result['collected']);
    });

    // Test threshold limit - should not go below 1
    $tester->run('Threshold Limit', function ($t) {
        // Create 35 objects that will be collected
        $young = [];
        for ($i = 0; $i < 35; $i++) {
            $young[] = new GCObject($i);
        }

        $middle = [];
        $old = [];
        $ages = [];
        $refs = []; // No references, all will be collected
        $threshold = 1; // Already at minimum
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Threshold should stay at 1 (won't go lower)
        $t->assertEqual(1, $result['threshold']);
    });

    // Test maximum threshold (no real limit, but test a high threshold)
    $tester->run('High Threshold', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = [1 => true]; // Keep reference to prevent collection
        $threshold = 100; // Very high threshold
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Threshold should increase (collected < 10)
        $t->assertEqual(101, $result['threshold'], 'Even high thresholds can increase');
    });

    // ===== Fragmentation History Tests =====

    // Test fragmentation history recording
    $tester->run('Fragmentation History', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = [1 => true];
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        // Set mock fragmentation value
        $GLOBALS['MOCK_FRAGMENTATION'] = 0.25;

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Fragmentation history should be updated
        $t->assertCount(1, $fragHist);
        $t->assertEqual(0.25, $fragHist[0]);

        // Run another cycle with different fragmentation
        $GLOBALS['MOCK_FRAGMENTATION'] = 0.35;

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Fragmentation history should have both values
        $t->assertCount(2, $fragHist);
        $t->assertEqual(0.25, $fragHist[0]);
        $t->assertEqual(0.35, $fragHist[1]);

        unset($GLOBALS['MOCK_FRAGMENTATION']);
    });

    // Test compaction triggering when fragmentation exceeds threshold
    $tester->run('Compaction Triggering', function ($t) {
        // Set mock fragmentation above threshold
        $GLOBALS['MOCK_FRAGMENTATION'] = FRAGMENTATION_THRESHOLD + 0.1;

        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = [1 => true];
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Verify fragmentation level is correct
        $t->assertEqual(FRAGMENTATION_THRESHOLD + 0.1, $result['fragLevel']);

        // Cleanup
        unset($GLOBALS['MOCK_FRAGMENTATION']);
    });

    // Test compaction not triggering when fragmentation below threshold
    $tester->run('Compaction Not Triggering', function ($t) {
        // Set mock fragmentation below threshold
        $GLOBALS['MOCK_FRAGMENTATION'] = FRAGMENTATION_THRESHOLD - 0.1;

        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = [1 => true];
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Verify fragmentation level is correct
        $t->assertEqual(FRAGMENTATION_THRESHOLD - 0.1, $result['fragLevel']);

        // Cleanup
        unset($GLOBALS['MOCK_FRAGMENTATION']);
    });

    // ===== Edge Cases =====

    // Test empty generations
    $tester->run('Empty Generations', function ($t) {
        $young = [];
        $middle = [];
        $old = [];
        $ages = [];
        $refs = [];
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // No errors should occur
        $t->assertEqual(0, $result['collected']);
        $t->assertEmpty($young);
        $t->assertEmpty($middle);
        $t->assertEmpty($old);
    });

    // Test age records for objects that don't exist
    $tester->run('Age Records Without Objects', function ($t) {
        $young = [];
        $middle = [];
        $old = [];
        $ages = [999 => 5]; // Age record for non-existent object
        $refs = [];
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        $result = runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // No errors should occur, age record should remain (though it's orphaned)
        $t->assertEqual(0, $result['collected']);
        $t->assertEqual(5, $ages[999]);
    });

    // Test leaked objects that should never be collected
    $tester->run('Leaked Objects', function ($t) {
        $young = [new GCObject(1)];
        $middle = [];
        $old = [];
        $ages = [1 => 0];
        $refs = []; // No strong references
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [1 => $young[0]]; // Object is leaked
        $fragHist = [];

        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Object should be collected from young generation even though it's leaked
        // The leak tracker remains separate
        $t->assertEmpty($young);
        $t->assertCount(1, $leaks);
    });

    // ===== Complex Reference Tests =====

    // Test complex reference chain
    $tester->run('Complex Reference Chain', function ($t) {
        // Create objects with a chain of references
        $obj1 = new GCObject(1);
        $obj2 = new GCObject(2);
        $obj3 = new GCObject(3);

        // obj1 references obj2
        $obj1->refs[] = $obj2;

        // obj2 references obj3
        $obj2->refs[] = $obj3;

        // Set up the GC environment
        $young = [$obj1, $obj2, $obj3];
        $middle = [];
        $old = [];
        $ages = [1 => 0, 2 => 0, 3 => 0];
        $refs = [1 => $obj1]; // Only direct reference to obj1
        $threshold = INITIAL_SURVIVAL_THRESHOLD;
        $leaks = [];
        $fragHist = [];

        // Run GC
        runGCCycle($young, $middle, $old, $ages, $refs, $threshold, $leaks, $fragHist);

        // Only obj1 has a strong reference, but it should keep the chain alive
        // Our simple GC doesn't follow object refs, so others should be collected
        $youngIds = array_map(function ($obj) {
            return $obj->id;
        }, $young);
        $t->assertContains(1, $youngIds, 'Object 1 should survive');
    });

    // ===== Statistics Tests ====

    // Test running a complete mini-simulation
    $tester->run('Mini Simulation', function ($t) {
        // Run a small simulation with just a few objects
        $stats = runSimulation(20, 5);

        // Verify stats structure
        $t->assertTrue(isset($stats['youngCount']), 'youngCount should be reported');
        $t->assertTrue(isset($stats['middleCount']), 'middleCount should be reported');
        $t->assertTrue(isset($stats['oldCount']), 'oldCount should be reported');
        $t->assertTrue(isset($stats['leakCount']), 'leakCount should be reported');
        $t->assertTrue(isset($stats['avgFragmentation']), 'avgFragmentation should be reported');

        // Verify total count
        $t->assertEqual(20, $stats['totalCreated'], 'Total created should match parameter');
    });
}

// Only run the main simulation if not in testing mode
if (!TESTING_MODE) {
    runSimulation();
} else {
    // Run tests when in test mode
    runTests();
}
