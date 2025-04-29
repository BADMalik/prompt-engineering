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
        if ($expectedCount !== $actualCount)