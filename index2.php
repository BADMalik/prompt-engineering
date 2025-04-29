<!-- 
/**
 * A Toy PHP Memory Profiler Demonstration
 * =======================================
 *
 * NOTE: This script is a highly simplified, *theoretical* demonstration of how you
 * might implement certain memory profiling techniques purely in userland PHP.  
 * Many of the functionalities (e.g., reading actual reference counts in real-time)
 * are extremely limited or not directly supported by PHP’s internal APIs. Therefore,
 * this script uses heuristics (debug_zval_dump, Reflection, spl_object_id, etc.)
 * and simulation to illustrate how one might structure and approach a more advanced
 * system.
 *
 * Usage:
 *   1) Make the script executable: chmod +x memory_profiler.php
 *   2) Run: ./memory_profiler.php [start|resume]
 *   3) While running, type commands in the inline console:
 *      - inspect <ObjectLabel>
 *      - graph <ObjectID>
 *      - zones
 *      - status
 *      - track <ClassName>
 *      - exit
 *
 * Features Demonstrated:
 *   1) Time-Series Logging and Delta Analysis
 *   2) Dynamic Object Graph Visualization
 *   3) Real-Time Memory Zone Classification
 *   4) In-Memory Heatmap and Memory Diff
 *   5) Shadow Cloning and Leak Injection Simulator
 *   6) Threaded Object Simulation (Coroutine/Async Emulation)
 *   7) Reflection-Based Property-Level Profiling
 *   8) Extension Hook Interface
 *   9) Inline Evaluation Console
 *  10) State Persistence and Resume Mode
 *
 * DISCLAIMER: This is *not* production-ready code. It is a teaching demonstration
 * that borrows from multiple advanced concepts. Many parts are stubbed out or
 * simulated. Use at your own risk!
 */

/* ---------------------------------------------------------------------------
 * Global Configuration and Setup
 * ------------------------------------------------------------------------- */
declare(ticks=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$PROFILER_STATE_FILE = __DIR__ . '/profiler_state.json';

// Simple color codes for CLI highlighting
function color($text, $colorCode)
{
    // Some basic ANSI color codes
    $colors = [
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'blue'   => "\033[34m",
        'reset'  => "\033[0m",
    ];
    return $colors[$colorCode] . $text . $colors['reset'];
}

/* ---------------------------------------------------------------------------
 * MemoryProfiler Class
 * ------------------------------------------------------------------------- */
class MemoryProfiler
{
    private static $instance;

    // Object storage: objectId => [ 'object' => object, 'label' => string, ... ]
    private $trackedObjects = [];
    private $refHistory = [];        // time => [objectId => refCount]
    private $baselineRefs = [];      // objectId => baseline reference count
    private $birthTime = [];         // objectId => time
    private $zones = [];             // objectId => 'short-lived'|'long-lived'|'suspicious'
    private $hooks = [];             // event => [callables]

    // For asynchronous simulation
    private $contexts = [];          // Used to hold a separate "profiler context" for each pseudo-thread
    private $leakClosures = [];      // Keep references to artificially leaked objects

    // Time tracking
    private $tickInterval = 1;       // seconds
    private $lastTickTime;
    private $startTime;
    private $resumeMode = false;

    private function __construct() {
        $this->startTime = time();
        $this->lastTickTime = time();
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ---------------------------------------------------------
     * Public API
     * ------------------------------------------------------- */
    public function resumeState($stateFile) {
        if (file_exists($stateFile)) {
            $json = file_get_contents($stateFile);
            $data = json_decode($json, true);
            if ($data) {
                echo "Resuming profiler state...\n";
                $this->trackedObjects   = $data['trackedObjects'] ?? [];
                $this->refHistory       = $data['refHistory']     ?? [];
                $this->baselineRefs     = $data['baselineRefs']   ?? [];
                $this->birthTime        = $data['birthTime']      ?? [];
                $this->zones            = $data['zones']          ?? [];
                $this->startTime        = $data['startTime']      ?? time();
                $this->resumeMode       = true;
            }
        }
    }

    public function persistState($stateFile)
    {
        $data = [
            'trackedObjects' => $this->trackedObjects,
            'refHistory'     => $this->refHistory,
            'baselineRefs'   => $this->baselineRefs,
            'birthTime'      => $this->birthTime,
            'zones'          => $this->zones,
            'startTime'      => $this->startTime,
        ];

        file_put_contents($stateFile, json_encode($data));
    }

    public function run()
    {
        // Main loop
        while (true) {
            // Time-based tick
            if (time() - $this->lastTickTime >= $this->tickInterval) {
                $this->profilerTick();
                $this->persistState($GLOBALS['PROFILER_STATE_FILE']);
                $this->lastTickTime = time();
            }

            // Check for any console commands
            $readStreams = [STDIN];
            $write = null; 
            $except = null;
            $modifiedStreams = stream_select($readStreams, $write, $except, 0);
            if ($modifiedStreams && in_array(STDIN, $readStreams)) {
                $cmd = trim(fgets(STDIN));
                $this->handleConsoleCommand($cmd);
            }

            // Simulate asynchronous "contexts" (generators)
            $this->simulateAsyncContexts();

            // Shadow cloning and leak injection
            $this->maybeShadowClone();
            $this->maybeInjectLeak();

            usleep(100000); // 0.1s
        }
    }

    /**
     * Track a new object with an optional label
     */
    public function trackObject($obj, $label = null)
    {
        $objId = spl_object_id($obj);

        if (!isset($this->trackedObjects[$objId])) {
            $label = $label ?: ("Object#" . $objId);
            $this->trackedObjects[$objId] = [
                'object' => $obj,
                'label'  => $label,
                'class'  => get_class($obj),
            ];
            $this->birthTime[$objId] = time();
            $this->zones[$objId] = 'short-lived'; // default
            $this->invokeHooks('creation', $obj);
        }
    }

    public function untrackObject($obj)
    {
        $objId = spl_object_id($obj);
        if (isset($this->trackedObjects[$objId])) {
            $this->invokeHooks('deletion', $obj);
            unset($this->trackedObjects[$objId]);
            unset($this->zones[$objId]);
            unset($this->birthTime[$objId]);
            // do not remove from baselineRefs, refHistory to keep historical data
        }
    }

    /**
     * Register a hook
     * events: creation, deletion, tracking
     */
    public function registerHook($event, callable $callback)
    {
        if (!isset($this->hooks[$event])) {
            $this->hooks[$event] = [];
        }
        $this->hooks[$event][] = $callback;
    }


    /* ---------------------------------------------------------
     * Internal Methods
     * ------------------------------------------------------- */
    private function profilerTick()
    {
        // Gather current refcounts
        $timestamp = time();
        $this->refHistory[$timestamp] = [];

        $currentMemory = memory_get_usage(true);
        $baselineMemory = isset($this->refHistory[$this->startTime]) 
            ? ($this->refHistory[$this->startTime]['_memoryUsage'] ?? $currentMemory)
            : $currentMemory;

        foreach ($this->trackedObjects as $objId => $arr) {
            $obj = $arr['object'];
            $refCount = $this->getRefCount($obj); // approximate
            $this->refHistory[$timestamp][$objId] = $refCount;

            // Record baseline if not set
            if (!isset($this->baselineRefs[$objId])) {
                $this->baselineRefs[$objId] = $refCount;
            }

            // Time delta / zone classification
            $lifetime = time() - $this->birthTime[$objId];
            $this->updateZone($objId, $lifetime);

            // Possibly do BFS/DFS to detect cycles
            $cycles = $this->detectCycles($obj);
            if ($cycles > 0) {
                echo color("[WARNING] Circular reference detected in Object #$objId (Cycle length: $cycles)\n", 'red');
            }

            // Reflection-based property-level check
            $this->propertyRefCheck($objId, $obj);
        }

        // Logging memory usage
        $this->refHistory[$timestamp]['_memoryUsage'] = $currentMemory;
        $this->refHistory[$timestamp]['_memoryDelta'] = $currentMemory - $baselineMemory;

        $this->analyzeRefDelta($timestamp);

        // End-of-tick summary
        echo color("[Tick] Time: ".date('H:i:s').", Objects: ".count($this->trackedObjects).", Memory: $currentMemory bytes\n",'green');
    }

    /**
     * Attempt to parse changes in reference counts and highlight spikes
     */
    private function analyzeRefDelta($timestamp)
    {
        // Early out
        $keys = array_keys($this->refHistory);
        $n = count($keys);
        if ($n < 2) return;

        $prevT = $keys[$n - 2];
        $currT = $keys[$n - 1];

        foreach ($this->trackedObjects as $objId => $info) {
            $prevRef = $this->refHistory[$prevT][$objId] ?? 0;
            $currRef = $this->refHistory[$currT][$objId] ?? 0;
            $delta = $currRef - $prevRef;
            if ($delta > 5) {
                echo color("[Spike] ".$info['label']." refcount increased by +$delta\n", 'yellow');
            } elseif ($delta < -5) {
                echo color("[Drop] ".$info['label']." refcount decreased by $delta\n", 'yellow');
            }
        }

        $prevMem = $this->refHistory[$prevT]['_memoryUsage'];
        $currMem = $this->refHistory[$currT]['_memoryUsage'];
        $memDelta = $currMem - $prevMem;
        if (abs($memDelta) > (1024 * 100)) { // e.g. 100KB threshold
            echo color("[Memory Delta] Change of $memDelta bytes\n", 'blue');
        }
    }

    /**
     * Reflection-based property check
     */
    private function propertyRefCheck($objId, $obj)
    {
        // Use reflection to get properties
        $refl = new ReflectionObject($obj);
        foreach ($refl->getProperties() as $prop) {
            $prop->setAccessible(true);
            if (!$prop->isInitialized($obj)) {
                continue;
            }
            $val = $prop->getValue($obj);
            // If there's a sub-object, track or note it
            if (is_object($val)) {
                // We'll track sub-object for demonstration
                // (In real usage, you might want to carefully decide recursion)
                $this->trackObject($val, get_class($val)."_subprop");
            }
        }
    }

    /**
     * Zone classification: short-lived, long-lived, suspicious
     */
    private function updateZone($objId, $lifetime)
    {
        if ($lifetime < 5) {
            // short-lived if under 5 seconds
            $this->zones[$objId] = 'short-lived';
        } elseif ($lifetime < 30) {
            // 5-30 seconds => mid range
            $this->zones[$objId] = 'long-lived';
        } else {
            // Over 30 => suspicious
            if ($this->zones[$objId] !== 'suspicious') {
                echo color("[WARNING] Object #$objId was short-lived but is now suspicious (>$lifetime s)\n", 'red');
            }
            $this->zones[$objId] = 'suspicious';
        }
    }

    /**
     * BFS/DFS to detect cycles. 
     * For demonstration, we do a naive reflection approach.
     * Returns cycle length or 0 if no cycle found
     */
    private function detectCycles($obj)
    {
        // We'll do a simplistic approach: track visited IDs
        // If we revisit the same ID, we found a cycle.
        $visited = [];
        return $this->dfsDetectCycle($obj, $visited);
    }

    private function dfsDetectCycle($obj, &$visited)
    {
        if (!is_object($obj)) return 0;

        $oid = spl_object_id($obj);
        if (isset($visited[$oid])) {
            // cycle found!
            return 1; 
        }
        $visited[$oid] = true;

        $refl = new ReflectionObject($obj);
        foreach ($refl->getProperties() as $prop) {
            $prop->setAccessible(true);
            if (!$prop->isInitialized($obj)) continue;
            $val = $prop->getValue($obj);
            if (is_object($val)) {
                $cycle = $this->dfsDetectCycle($val, $visited);
                if ($cycle > 0) {
                    return $cycle + 1;
                }
            }
        }
        return 0;
    }

    private function getRefCount(&$obj)
    {
        // Fake a refcount using debug_zval_dump
        // This trick can break easily in modern PHP due to copy-on-write.
        // We'll parse the text to find "refcount(".
        ob_start();
        debug_zval_dump($obj);
        $dump = ob_get_clean();
        if (preg_match('/refcount\((\d+)\)/', $dump, $m)) {
            return (int)$m[1];
        }
        return 1; // fallback
    }

    private function simulateAsyncContexts()
    {
        foreach ($this->contexts as $i => &$co) {
            if (!$co->valid()) {
                unset($this->contexts[$i]);
                continue;
            }
            $co->next();
            // e.g. track or produce objects
            if ($co->valid()) {
                $obj = $co->current();
                // track
                $this->trackObject($obj, "CoroutineObject");
            }
        }
    }

    /**
     * Demonstration of random clone
     */
    private function maybeShadowClone()
    {
        // Random chance
        if (rand(0, 100) < 5 && count($this->trackedObjects) > 0) {
            $keys = array_keys($this->trackedObjects);
            $pick = $keys[array_rand($keys)];
            $original = $this->trackedObjects[$pick]['object'];
            // Clone
            $clone = clone $original;
            $this->trackObject($clone, $this->trackedObjects[$pick]['label'].'_clone');
            echo color("[ShadowClone] Created a clone of Object #$pick\n", 'yellow');
        }
    }

    /**
     * Demonstration of memory leak injection - capturing references in closures
     */
    private function maybeInjectLeak()
    {
        // Random chance
        if (rand(0, 100) < 3 && count($this->trackedObjects) > 0) {
            $keys = array_keys($this->trackedObjects);
            $pick = $keys[array_rand($keys)];
            $victim = $this->trackedObjects[$pick]['object'];
            $closure = function() use ($victim) {
                // artificially hold reference
                return spl_object_id($victim);
            };
            $this->leakClosures[] = $closure;
            echo color("[LeakInjection] Potential leak introduced for Object #$pick\n", 'red');
        }
    }

    private function handleConsoleCommand($command)
    {
        $parts = explode(' ', $command);
        $cmd = strtolower(array_shift($parts));

        switch($cmd) {
            case 'inspect':
                $label = implode(' ', $parts);
                $this->cmdInspect($label);
                break;
            case 'graph':
                $objId = implode(' ', $parts);
                $this->cmdGraph($objId);
                break;
            case 'zones':
                $this->cmdZones();
                break;
            case 'status':
                $this->cmdStatus();
                break;
            case 'track':
                $className = implode(' ', $parts);
                $this->cmdTrack($className);
                break;
            case 'exit':
                echo "Exiting profiler...\n";
                $this->persistState($GLOBALS['PROFILER_STATE_FILE']);
                exit(0);
            default:
                echo "Unknown command: $cmd\n";
        }
    }

    private function cmdInspect($label)
    {
        foreach ($this->trackedObjects as $objId => $meta) {
            if ($meta['label'] === $label) {
                echo "Inspecting {$meta['label']} (#$objId)\n";
                echo "  Class: {$meta['class']}\n";
                echo "  Zone:  {$this->zones[$objId]}\n";
                echo "  Refcount: {$this->getRefCount($meta['object'])}\n";
                return;
            }
        }
        echo "No object with label '$label' found.\n";
    }

    private function cmdGraph($objId)
    {
        $objId = (int)$objId;
        if (!isset($this->trackedObjects[$objId])) {
            echo "Object #$objId not found.\n";
            return;
        }
        echo "Object Graph for #$objId:\n";
        // BFS or DFS representation
        $visited = [];
        $this->printGraph($this->trackedObjects[$objId]['object'], 0, $visited);
    }

    private function printGraph($obj, $level, &$visited)
    {
        if (!is_object($obj)) return;
        $oid = spl_object_id($obj);
        if (isset($visited[$oid])) {
            echo str_repeat('  ', $level). "-> #$oid [CYCLE DETECTED]\n";
            return;
        }
        $visited[$oid] = true;
        $metaLabel = isset($this->trackedObjects[$oid]) 
            ? $this->trackedObjects[$oid]['label']
            : ("Unknown #$oid");
        echo str_repeat('  ', $level). "-> $metaLabel (#$oid)\n";

        $refl = new ReflectionObject($obj);
        foreach ($refl->getProperties() as $prop) {
            $prop->setAccessible(true);
            if (!$prop->isInitialized($obj)) continue;
            $val = $prop->getValue($obj);
            if (is_object($val)) {
                $this->printGraph($val, $level+1, $visited);
            }
        }
    }

    private function cmdZones()
    {
        $counts = ['short-lived' => 0, 'long-lived' => 0, 'suspicious' => 0];
        foreach ($this->zones as $zone) {
            if (isset($counts[$zone])) {
                $counts[$zone]++;
            }
        }
        echo "Zones Summary:\n";
        foreach ($counts as $zone => $cnt) {
            echo "  $zone => $cnt\n";
        }
    }

    private function cmdStatus()
    {
        $mem = memory_get_usage(true);
        echo " ---- Profiler Status ---- \n";
        echo "Tracked Objects: ".count($this->trackedObjects)."\n";
        echo "Memory Usage   : $mem bytes\n";
        echo "Uptime         : ".(time() - $this->startTime)." seconds\n";
    }

    private function cmdTrack($className)
    {
        if (!class_exists($className)) {
            echo "Class $className not found. Creating a dummy class on the fly.\n";
            eval("class $className {}");
        }
        // Instantiate
        $obj = new $className();
        $this->trackObject($obj, "$className"."_manual");
        echo "Tracked a new instance of $className\n";
    }

    private function invokeHooks($event, $obj)
    {
        if (!isset($this->hooks[$event])) return;
        foreach ($this->hooks[$event] as $hook) {
            $hook($obj);
        }
    }

} // end MemoryProfiler

/* ---------------------------------------------------------------------------
 * Example asynchronous generator (thread simulation)
 * ------------------------------------------------------------------------- */
function asyncSimulator()
{
    for ($i = 0; $i < 5; $i++) {
        // Simulate some work
        yield (object)['index' => $i];
        usleep(200000); // 0.2s
    }
}

/* ---------------------------------------------------------------------------
 * MAIN: Parse Arguments, Start or Resume Profiler
 * ------------------------------------------------------------------------- */
function main($argv)
{
    $profiler = MemoryProfiler::getInstance();

    $action = $argv[1] ?? 'start';
    if ($action === 'resume') {
        $profiler->resumeState($GLOBALS['PROFILER_STATE_FILE']);
    }

    // Register a simple hook example
    $profiler->registerHook('creation', function($obj) {
        echo color("[Hook] An object was created: #".spl_object_id($obj)."\n", 'blue');
    });

    // Add some "thread" contexts
    $coro = asyncSimulator();
    $coro2 = asyncSimulator();
    // Because we cannot directly add contexts from outside, let's do it inside the profiler
    // but we'll do it from outside for demonstration:
    $profilerReflection = new ReflectionClass(MemoryProfiler::class);
    $contextsProp = $profilerReflection->getProperty('contexts');
    $contextsProp->setAccessible(true);
    $contexts = $contextsProp->getValue($profiler);
    $contexts[] = $coro;
    $contexts[] = $coro2;
    $contextsProp->setValue($profiler, $contexts);

    // Start main loop
    $profiler->run();
}

// Run only if executed directly (not included)
if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
    main($argv);
} -->


<?php

class MemoryProfiler
{
    private $objects = [];
    private $logs = [];
    private $baseline = [];
    private $zones = [];
    private $heatmap = [];
    private $stateFile = 'profiler_state.json';
    private $hooks = [];

    public function __construct()
    {
        if (file_exists($this->stateFile)) {
            $this->loadState();
        }
    }

    public function trackObject($object)
    {
        $id = spl_object_id($object);
        if (!isset($this->objects[$id])) {
            $this->objects[$id] = [
                'refcount' => 0,
                'properties' => [],
                'creation_time' => microtime(true),
                'last_access' => microtime(true),
                'zone' => 'Short-lived'
            ];
        }
        $this->objects[$id]['refcount']++;
        $this->objects[$id]['last_access'] = microtime(true);
        $this->updateHeatmap($id);
        $this->runHooks('onCreate', $object);
    }

    public function untrackObject($object)
    {
        $id = spl_object_id($object);
        if (isset($this->objects[$id])) {
            $this->objects[$id]['refcount']--;
            if ($this->objects[$id]['refcount'] <= 0) {
                unset($this->objects[$id]);
            }
            $this->runHooks('onDelete', $object);
        }
    }

    public function logDelta()
    {
        $currentTime = time();
        if (!isset($this->logs[$currentTime])) {
            $this->logs[$currentTime] = [];
        }

        foreach ($this->objects as $id => $obj) {
            if (!isset($this->logs[$currentTime][$id])) {
                $this->logs[$currentTime][$id] = $obj['refcount'];
            } else {
                $delta = $obj['refcount'] - $this->logs[$currentTime][$id];
                echo "Object $id: Delta = $delta\n";
                if (abs($delta) > 5) {  // Highlight spikes or drops
                    echo "Spike/Drop detected for Object $id\n";
                }
                $this->logs[$currentTime][$id] = $obj['refcount'];
            }
        }
    }

    public function detectCircularReferences()
    {
        $visited = [];
        $stack = [];

        foreach ($this->objects as $id => $obj) {
            if (!isset($visited[$id])) {
                $this->dfs($id, $visited, $stack);
            }
        }
    }

    private function dfs($id, &$visited, &$stack)
    {
        $visited[$id] = true;
        $stack[] = $id;

        // Simulate graph traversal and check for cycles
        foreach ($this->objects as $objId => $obj) {
            if ($obj['refcount'] > 0 && !isset($visited[$objId])) {
                $this->dfs($objId, $visited, $stack);
            }
        }

        $stack = array_unique($stack);
        if (count($stack) != count($visited)) {
            echo "Circular reference detected involving: " . implode(', ', $stack) . "\n";
        }
        array_pop($stack);
    }

    public function classifyZones()
    {
        foreach ($this->objects as $id => $obj) {
            $lifespan = microtime(true) - $obj['creation_time'];
            if ($lifespan < 1) {
                $obj['zone'] = 'Short-lived';
            } elseif ($lifespan < 10) {
                $obj['zone'] = 'Long-lived';
            } else {
                $obj['zone'] = 'Suspicious';
                echo "Suspicious object detected: $id\n";
            }
            $this->objects[$id]['zone'] = $obj['zone'];
        }
    }

    public function updateHeatmap($id)
    {
        if (!isset($this->heatmap[$id])) {
            $this->heatmap[$id] = 0;
        }
        $this->heatmap[$id]++;
    }

    public function showHeatmap()
    {
        arsort($this->heatmap);
        foreach ($this->heatmap as $id => $count) {
            echo "Hot Object $id: Reference Count = $count\n";
        }
    }

    public function simulateLeaks()
    {
        foreach ($this->objects as $object) {
            if (rand(0, 100) < 5) {  // 5% chance to clone
                clone $object;
                echo "Simulated clone for object " . spl_object_id($object) . "\n";
            }
            if (rand(0, 100) < 2) {  // 2% chance to create a leak
                $leak = function () use ($object) {};
                echo "Simulated leak for object " . spl_object_id($object) . "\n";
            }
        }
    }

    public function runHooks($event, $object)
    {
        if (isset($this->hooks[$event])) {
            foreach ($this->hooks[$event] as $hook) {
                call_user_func($hook, $object);
            }
        }
    }

    public function addHook($event, $callback)
    {
        if (!isset($this->hooks[$event])) {
            $this->hooks[$event] = [];
        }
        $this->hooks[$event][] = $callback;
    }

    public function saveState()
    {
        file_put_contents($this->stateFile, json_encode([
            'objects' => $this->objects,
            'logs' => $this->logs,
            'heatmap' => $this->heatmap,
        ]));
    }

    public function loadState()
    {
        $data = json_decode(file_get_contents($this->stateFile), true);
        $this->objects = $data['objects'];
        $this->logs = $data['logs'];
        $this->heatmap = $data['heatmap'];
    }

    public function console()
    {
        while (true) {
            $input = readline("Profiler> ");
            $parts = explode(' ', $input);
            $command = $parts[0];

            switch ($command) {
                case 'inspect':
                    $this->inspectObject($parts[1]);
                    break;
                case 'graph':
                    $this->graphObject($parts[1]);
                    break;
                case 'zones':
                    $this->showZones();
                    break;
                case 'track':
                    $this->trackNewObject(new $parts[1]());
                    break;
                case 'quit':
                    return;
            }
        }
    }

    private function inspectObject($id)
    {
        if (isset($this->objects[$id])) {
            print_r($this->objects[$id]);
        } else {
            echo "Object $id not found\n";
        }
    }

    private function graphObject($id)
    {
        // Implement BFS/DFS to display object graph
        echo "Graph for object $id\n";
    }

    private function showZones()
    {
        foreach ($this->objects as $id => $obj) {
            echo "Object $id: Zone = {$obj['zone']}\n";
        }
    }

    private function trackNewObject($object)
    {
        $this->trackObject($object);
    }
}

$profiler = new MemoryProfiler();

// Simulate object creation
for ($i = 0; $i < 100; $i++) {
    $obj = new stdClass();
    $profiler->trackObject($obj);
}

// Log deltas every second
while (true) {
    $profiler->logDelta();
    $profiler->classifyZones();
    $profiler->showHeatmap();
    $profiler->simulateLeaks();
    $profiler->saveState();
    sleep(1);
}

// Run inline evaluation console
$profiler->console();
?>