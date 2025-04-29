<?php
// ===============================================================
// PHP Standalone Simulated Generational Garbage Collector
// ===============================================================

// Configuration and state variables
define('TOTAL_OBJECTS', 5000);
define('INITIAL_SURVIVAL_THRESHOLD', 3);
define('GC_CYCLE_INTERVAL', 200);
define('LEAK_PROBABILITY', 0.01);
define('FRAGMENTATION_THRESHOLD', 0.2);
define('COMPACTION_COST_MS', [50, 200]);
define('TESTING_MODE', isset($argv[1]) && $argv[1] === 'test');

$youngGeneration = [];
$middleGeneration = [];
$oldGeneration = [];
$objectAges = [];
$strongReferences = [];
$survivalThreshold = INITIAL_SURVIVAL_THRESHOLD;
$leakTracker = [];
$fragmentationHistory = [];

class GCObject
{
    public $id;
    public $refs = [];
    public function __construct($id)
    {
        $this->id = $id;
    }
}

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

    if (!TESTING_MODE) {
        usleep($cost * 1000);
    }
}

function calculateFragmentation($young, $middle)
{
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

function runGCCycle(&$young, &$middle, &$old, &$ages, &$refs, &$threshold, &$leaks, &$fragHist)
{
    logEvent('GC', "Starting GC cycle...");
    $collected = 0;

    if (is_array($young)) {
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
    }

    if (is_array($middle)) {
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
    }

    $fragLevel = calculateFragmentation($young, $middle);
    $fragHist[] = $fragLevel;
    logEvent('FRAGMENTATION', sprintf("Fragmentation Level: %.2f%%", $fragLevel * 100));

    if ($fragLevel > FRAGMENTATION_THRESHOLD) {
        simulateCompaction();
    }

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

function runSimulation($objectCount = TOTAL_OBJECTS, $cycleInterval = GC_CYCLE_INTERVAL)
{
    global $youngGeneration, $middleGeneration, $oldGeneration, $objectAges,
        $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory;

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

        if (count($strongReferences) > 1 && rand(0, 1)) {
            $refId = array_rand($strongReferences);
            $obj->refs[] = $strongReferences[$refId];
            logEvent('REFERENCE', "Object {$obj->id} references {$refId}");
        }

        if (rand(0, 10000) / 10000 < LEAK_PROBABILITY) {
            $leakTracker[$obj->id] = $obj;
            logEvent('LEAK', "Leaked object {$obj->id} (will never be GC'd)");
        }

        if ($i > 0 && $i % $cycleInterval == 0) {
            runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
        }
    }

    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);

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

class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

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

$tester = new SimpleTestFramework();

// Basic Functionality
$tester->run('GCObject creation and properties', function ($test) {
    $obj = new GCObject(1);
    $test->assertEqual(1, $obj->id, "GCObject ID should be 1");
    $test->assertEmpty($obj->refs, "GCObject refs should be empty");
});

$tester->run('GCObject reference assignment', function ($test) {
    $obj1 = new GCObject(1);
    $obj2 = new GCObject(2);
    $obj1->refs[] = $obj2;
    $test->assertCount(1, $obj1->refs, "GCObject refs should have one reference");
    $test->assertContains($obj2, $obj1->refs, "GCObject refs should contain obj2");
});

$tester->run('logEvent function behavior in testing vs non-testing mode', function ($test) {
    $GLOBALS['OVERRIDE_TESTING_MODE'] = true;
    logEvent('TEST', 'Test Message');
    $GLOBALS['OVERRIDE_TESTING_MODE'] = false;
    logEvent('TEST', 'Test Message');
    // No assertions needed here, just checking if the function runs without errors
});

// Generational Structure
$tester->run('Young generation object collection', function ($test) {
    global $youngGeneration, $strongReferences;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($youngGeneration, "Young generation should be empty after collection");
});

$tester->run('Middle generation object collection', function ($test) {
    global $middleGeneration, $strongReferences;
    $obj = new GCObject(1);
    $middleGeneration[] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($middleGeneration, "Middle generation should be empty after collection");
});

$tester->run('Promotion from young to middle generation', function ($test) {
    global $youngGeneration, $middleGeneration, $objectAges, $strongReferences;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    $objectAges[$obj->id] = INITIAL_SURVIVAL_THRESHOLD;
    $strongReferences[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($youngGeneration, "Young generation should be empty after promotion");
    $test->assertCount(1, $middleGeneration, "Middle generation should have one object after promotion");
});

$tester->run('Promotion from middle to old generation', function ($test) {
    global $middleGeneration, $oldGeneration, $objectAges, $strongReferences;
    $obj = new GCObject(1);
    $middleGeneration[] = $obj;
    $objectAges[$obj->id] = $survivalThreshold * 2;
    $strongReferences[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($middleGeneration, "Middle generation should be empty after promotion");
    $test->assertCount(1, $oldGeneration, "Old generation should have one object after promotion");
});

// Object Aging System
$tester->run('Age increment for surviving objects', function ($test) {
    global $youngGeneration, $objectAges, $strongReferences;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    $objectAges[$obj->id] = 0;
    $strongReferences[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEqual(1, $objectAges[$obj->id], "Object age should be incremented to 1");
});

$tester->run('Proper age tracking across generations', function ($test) {
    global $youngGeneration, $middleGeneration, $objectAges, $strongReferences;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    $objectAges[$obj->id] = INITIAL_SURVIVAL_THRESHOLD - 1;
    $strongReferences[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(1, $middleGeneration, "Object should be promoted to middle generation");
    $test->assertEqual(INITIAL_SURVIVAL_THRESHOLD, $objectAges[$obj->id], "Object age should be incremented to " . INITIAL_SURVIVAL_THRESHOLD);
});

// Object Collection
$tester->run('Collection of unreferenced objects in young generation', function ($test) {
    global $youngGeneration, $strongReferences;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($youngGeneration, "Young generation should be empty after collection");
});

$tester->run('Collection of unreferenced objects in middle generation', function ($test) {
    global $middleGeneration, $strongReferences;
    $obj = new GCObject(1);
    $middleGeneration[] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($middleGeneration, "Middle generation should be empty after collection");
});

$tester->run('Handling of circular references', function ($test) {
    global $youngGeneration, $strongReferences;
    $obj1 = new GCObject(1);
    $obj2 = new GCObject(2);
    $obj1->refs[] = $obj2;
    $obj2->refs[] = $obj1;
    $youngGeneration[] = $obj1;
    $youngGeneration[] = $obj2;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(2, $youngGeneration, "Young generation should still have both objects due to circular references");
});

// Dynamic Threshold Tuning
$tester->run('Increase threshold when collection is low (<10 objects)', function ($test) {
    global $youngGeneration, $strongReferences, $survivalThreshold;
    $survivalThreshold = 1;
    for ($i = 0; $i < 5; $i++) {
        $obj = new GCObject($i);
        $youngGeneration[] = $obj;
        $strongReferences[$obj->id] = $obj;
    }
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEqual(2, $survivalThreshold, "Threshold should be increased to 2");
});

$tester->run('Decrease threshold when collection is high (>30 objects)', function ($test) {
    global $youngGeneration, $strongReferences, $survivalThreshold;
    $survivalThreshold = 3;
    for ($i = 0; $i < 35; $i++) {
        $obj = new GCObject($i);
        $youngGeneration[] = $obj;
    }
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEqual(2, $survivalThreshold, "Threshold should be decreased to 2");
});

$tester->run('Lower threshold boundary (should never go below 1)', function ($test) {
    global $youngGeneration, $strongReferences, $survivalThreshold;
    $survivalThreshold = 1;
    for ($i = 0; $i < 5; $i++) {
        $obj = new GCObject($i);
        $youngGeneration[] = $obj;
    }
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEqual(1, $survivalThreshold, "Threshold should remain at 1");
});

$tester->run('High threshold behavior (very large thresholds should still work)', function ($test) {
    global $youngGeneration, $strongReferences, $survivalThreshold;
    $survivalThreshold = 100;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    $strongReferences[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(1, $youngGeneration, "Young generation should still have the object due to high threshold");
});

// Memory Fragmentation
$tester->run('Calculation with empty generations', function ($test) {
    global $youngGeneration, $middleGeneration;
    $GLOBALS['MOCK_FRAGMENTATION'] = 0.1;
    $fragLevel = calculateFragmentation($youngGeneration, $middleGeneration);
    $test->assertEqual(0.1, $fragLevel, "Fragmentation should be 0.1");
});

$tester->run('Calculation with non-empty generations', function ($test) {
    global $youngGeneration, $middleGeneration;
    $youngGeneration[] = new GCObject(1);
    $middleGeneration[] = new GCObject(2);
    $fragLevel = calculateFragmentation($youngGeneration, $middleGeneration);
    $test->assertTrue($fragLevel >= 0 && $fragLevel <= 1, "Fragmentation should be between 0 and 1");
});

$tester->run('Fragmentation history recording across GC cycles', function ($test) {
    global $fragmentationHistory;
    $GLOBALS['MOCK_FRAGMENTATION'] = 0.2;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(1, $fragmentationHistory, "Fragmentation history should have one entry");
    $test->assertEqual(0.2, $fragmentationHistory[0], "Fragmentation history should record 0.2");
});

// Compaction
$tester->run('Triggering when fragmentation exceeds threshold', function ($test) {
    global $youngGeneration, $middleGeneration, $fragmentationHistory;
    $GLOBALS['MOCK_FRAGMENTATION'] = FRAGMENTATION_THRESHOLD + 0.1;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertTrue(in_array('COMPACTION', $fragmentationHistory), "Compaction should be triggered");
});

$tester->run('Not triggering when fragmentation is below threshold', function ($test) {
    global $youngGeneration, $middleGeneration, $fragmentationHistory;
    $GLOBALS['MOCK_FRAGMENTATION'] = FRAGMENTATION_THRESHOLD - 0.1;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertFalse(in_array('COMPACTION', $fragmentationHistory), "Compaction should not be triggered");
});

$tester->run('Proper execution and mocking during tests', function ($test) {
    global $youngGeneration, $middleGeneration;
    $GLOBALS['MOCK_FRAGMENTATION'] = FRAGMENTATION_THRESHOLD + 0.1;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertTrue(in_array('COMPACTION', $fragmentationHistory), "Compaction should be mocked and triggered");
});

// Memory Leak Simulation
$tester->run('Tracking of leaked objects', function ($test) {
    global $leakTracker;
    $obj = new GCObject(1);
    $leakTracker[$obj->id] = $obj;
    $test->assertCount(1, $leakTracker, "Leak tracker should have one leaked object");
});

$tester->run('Behavior of leaked objects during collection', function ($test) {
    global $youngGeneration, $leakTracker;
    $obj = new GCObject(1);
    $youngGeneration[] = $obj;
    $leakTracker[$obj->id] = $obj;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(1, $youngGeneration, "Young generation should still have the leaked object");
});

// Edge Cases
$tester->run('Empty generations', function ($test) {
    global $youngGeneration, $middleGeneration, $oldGeneration;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertEmpty($youngGeneration, "Young generation should remain empty");
    $test->assertEmpty($middleGeneration, "Middle generation should remain empty");
    $test->assertEmpty($oldGeneration, "Old generation should remain empty");
});

$tester->run('Age records for non-existent objects', function ($test) {
    global $objectAges;
    $test->assertEmpty($objectAges, "Object ages should be empty for non-existent objects");
});

$tester->run('Large numbers of objects', function ($test) {
    global $youngGeneration, $strongReferences;
    for ($i = 0; $i < 1000; $i++) {
        $obj = new GCObject($i);
        $youngGeneration[] = $obj;
        $strongReferences[$obj->id] = $obj;
    }
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(1000, $youngGeneration, "Young generation should have 1000 objects");
});

$tester->run('Complex reference patterns', function ($test) {
    global $youngGeneration, $strongReferences;
    $obj1 = new GCObject(1);
    $obj2 = new GCObject(2);
    $obj3 = new GCObject(3);
    $obj1->refs[] = $obj2;
    $obj2->refs[] = $obj3;
    $obj3->refs[] = $obj1;
    $youngGeneration[] = $obj1;
    $youngGeneration[] = $obj2;
    $youngGeneration[] = $obj3;
    runGCCycle($youngGeneration, $middleGeneration, $oldGeneration, $objectAges, $strongReferences, $survivalThreshold, $leakTracker, $fragmentationHistory);
    $test->assertCount(3, $youngGeneration, "Young generation should still have all three objects due to complex references");
});

// Full Simulation
$tester->run('End-to-end test of the garbage collection system', function ($test) {
    $stats = runSimulation(1000, 100);
    $test->assertGreaterThan(0, $stats['youngCount'], "Young generation should have some objects");
    $test->assertGreaterThan(0, $stats['middleCount'], "Middle generation should have some objects");
    $test->assertGreaterThan(0, $stats['oldCount'], "Old generation should have some objects");
    $test->assertGreaterThan(0, $stats['leakCount'], "Leak tracker should have some leaked objects");
    $test->assertGreaterThan(0, $stats['avgFragmentation'], "Average fragmentation should be greater than 0");
});

$tester->summary();
