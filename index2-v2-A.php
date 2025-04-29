<?php

/**
 * A single-file PHP Memory Profiler demonstration script
 * that showcases how one might implement real-time tracking, 
 * time-series delta analysis, object graph visualization, 
 * zones classification, heatmaps, leak simulation, 
 * generator-based concurrency, reflection at the property level, 
 * hook interfaces, an inline CLI console, and persisted state.
 *
 * DISCLAIMER:
 * This script is a conceptual demonstration to illustrate how each 
 * requirement could be structured or approached in a single executable file.
 * Production use would require additional error handling, performance 
 * optimizations, and security considerations.
 *
 * Usage:
 *   1) Make this file executable (chmod +x memory_profiler.php).
 *   2) Run it directly: ./memory_profiler.php
 *   3) Optionally pass "--resume" to attempt resuming from a previous session.
 */

/*************************************************************
 * Global Includes / Helpers
 *************************************************************/
if (version_compare(PHP_VERSION, '7.4', '<')) {
    fwrite(STDERR, "PHP 7.4 or higher is required.\n");
    exit(1);
}

/**
 * Quick color helpers for CLI output.
 */
function color($string, $colorCode)
{
    return "\033[" . $colorCode . "m" . $string . "\033[0m";
}

/**
 * Simple prompt for the inline evaluation console.
 */
function prompt($promptStr = "> ")
{
    echo $promptStr;
    return trim(fgets(STDIN));
}

/*************************************************************
 * MemoryObject - tracks a single object's metadata.
 *************************************************************/
class MemoryObject
{
    public $id;
    public $label;
    public $creationTime;
    public $lastAccessTime;
    public $refCount;
    public $zone; // short-lived, long-lived, suspicious
    public $properties = [];
    public $generatorContext = null; // for "threaded" simulation

    public function __construct($label)
    {
        $this->id = spl_object_id($this);
        $this->label = $label;
        $this->creationTime = microtime(true);
        $this->lastAccessTime = $this->creationTime;
        $this->refCount = 1;
        $this->zone = 'short-lived';
    }

    public function updateRefCount()
    {
        // In a real system, you might track refcount with advanced methods.
        // We'll mock it for demonstration.
        $this->refCount = rand(1, 10);
    }

    public function updateZone()
    {
        $age = microtime(true) - $this->creationTime;
        // Simple example: 
        // < 5sec => short-lived, 
        // > 5sec => long-lived, 
        // if they keep toggling references, mark suspicious
        if ($age < 5 && $this->zone !== 'suspicious') {
            $this->zone = 'short-lived';
        } elseif ($age >= 5 && $this->zone !== 'suspicious') {
            $this->zone = 'long-lived';
        }
    }

    public function reflectProperties()
    {
        // Reflection-based property-level examination
        $ref = new ReflectionObject($this);
        $props = $ref->getProperties();
        $this->properties = [];
        foreach ($props as $p) {
            if ($p->isPublic()) {
                $propName = $p->getName();
                $this->properties[$propName] = $this->$propName;
            }
        }
    }

    public function incrementRef()
    {
        $this->refCount++;
        $this->lastAccessTime = microtime(true);
    }

    public function decrementRef()
    {
        $this->refCount = max(0, $this->refCount - 1);
    }
}

/*************************************************************
 * MemoryProfiler - The main orchestrator that tracks objects,
 * logs changes, detects leaks, runs time-series updates, etc.
 *************************************************************/
class MemoryProfiler
{
    private static $instance;
    private $startTime;
    private $timeSeries = [];        // timestamp => [objectId => refCount]
    private $baselineMemory;
    private $objects = [];           // objectId => MemoryObject
    private $hooks = [];             // user-defined hooks
    private $logFile = 'memory_profiler.log';
    private $stateFile = 'memory_profiler.state';
    private $tickInterval = 1;       // seconds
    private $stop = false;

    /**
     * For simulating concurrency:
     * We'll keep "virtual threads" as generators.
     */
    private $generatorPool = [];

    private function __construct()
    {
        $this->startTime = microtime(true);
        $this->baselineMemory = memory_get_usage(true);
        $this->log("Profiler started. Baseline memory: " . $this->baselineMemory);
    }

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /*************************************************************
     * Hooks Registration
     *************************************************************/
    public function registerHook($eventName, callable $callback)
    {
        if (!isset($this->hooks[$eventName])) {
            $this->hooks[$eventName] = [];
        }
        $this->hooks[$eventName][] = $callback;
    }

    private function triggerHook($eventName, ...$args)
    {
        if (!isset($this->hooks[$eventName])) {
            return;
        }
        foreach ($this->hooks[$eventName] as $callback) {
            call_user_func_array($callback, $args);
        }
    }

    /*************************************************************
     * State Persistence
     *************************************************************/
    public function saveState()
    {
        $data = [
            'objects' => $this->objects,
            'timeSeries' => $this->timeSeries,
            'startTime' => $this->startTime,
            'baselineMemory' => $this->baselineMemory,
        ];
        file_put_contents($this->stateFile, serialize($data));
        $this->log("State saved.");
    }

    public function loadState()
    {
        if (!is_file($this->stateFile)) {
            return false;
        }
        $data = unserialize(file_get_contents($this->stateFile));
        if (!is_array($data)) {
            return false;
        }
        $this->objects = $data['objects'] ?? [];
        $this->timeSeries = $data['timeSeries'] ?? [];
        $this->startTime = $data['startTime'] ?? microtime(true);
        $this->baselineMemory = $data['baselineMemory'] ?? memory_get_usage(true);
        $this->log("State loaded from previous session.");
        return true;
    }

    /*************************************************************
     * Object Creation / Deletion Simulations
     *************************************************************/
    public function createObject($label = null)
    {
        $label = $label ?: 'Object_' . uniqid();
        $obj = new MemoryObject($label);
        $id = $obj->id;
        $this->objects[$id] = $obj;

        $this->triggerHook('onObjectCreate', $obj);
        return $obj;
    }

    public function deleteObject($id)
    {
        if (isset($this->objects[$id])) {
            $obj = $this->objects[$id];
            unset($this->objects[$id]);
            $this->triggerHook('onObjectDelete', $obj);
        }
    }

    /*************************************************************
     * Random Cloning and Artificial Leak Injection
     *************************************************************/
    public function simulateLoad()
    {
        // Randomly clone an existing object
        if (count($this->objects) > 0) {
            $existingObjKeys = array_keys($this->objects);
            $randomKey = $existingObjKeys[array_rand($existingObjKeys)];
            $cloneLabel = $this->objects[$randomKey]->label . "_clone";
            $this->createObject($cloneLabel);
        }

        // Artificial leak: create a closure capturing an object reference
        // that is never properly released
        $leakCreator = function ($obj) {
            // This closure retains $obj in an up-level reference 
            // effectively "leaking" it if not handled
            return function () use ($obj) {
                // no-op
            };
        };

        // We'll pick a random object to leak
        if (count($this->objects) > 0) {
            $randomKey2 = array_rand($this->objects);
            $obj = $this->objects[$randomKey2];
            $leaked = $leakCreator($obj);
            // Just store it in a property
            $obj->leak = $leaked;
        }
    }

    /*************************************************************
     * Time-Series Logging & Delta, plus Graph & Zone classification
     *************************************************************/
    public function tick()
    {
        $timestamp = time();
        $this->timeSeries[$timestamp] = [];

        // Update each object's refcount, zone, reflect properties
        // Also track "hot" references
        foreach ($this->objects as $id => $obj) {
            $oldRefCount = $obj->refCount;
            $obj->updateRefCount();
            $obj->updateZone();
            $obj->reflectProperties();

            $this->timeSeries[$timestamp][$id] = $obj->refCount;
            $delta = $obj->refCount - $oldRefCount;

            // If there's a significant delta, highlight it
            if (abs($delta) > 3) {
                $this->log(
                    "RefCount Spike for {$obj->label} [ID:$id] Delta: "
                        . color($delta, '1;33')
                );
            }
        }

        // Detect cyclical references using BFS or DFS in a text-based manner
        $this->detectCycles();

        // In-Memory Heatmap / Memory Diff
        // Simple approach: if refCount > 7, consider "hot"
        foreach ($this->objects as $id => $obj) {
            if ($obj->refCount > 7) {
                $this->log(
                    color("[HEATMAP] Object '{$obj->label}' [ID:$id] is hot with refCount={$obj->refCount}", "1;31")
                );
            }
        }
        $this->memoryDiff();

        // Possibly run concurrency tasks
        $this->runGenerators();
    }

    private function detectCycles()
    {
        // In a real system, we'd build a graph of object references.
        // We'll do a naive BFS that looks for references in properties.
        $visited = [];
        foreach ($this->objects as $id => $obj) {
            if (!isset($visited[$id])) {
                $cycle = $this->bfsDetectCycle($obj, [], $visited);
                if ($cycle) {
                    $this->log(
                        color("[CYCLE DETECTED] Object ID:{$obj->id} Cycle: " . json_encode($cycle), "1;35")
                    );
                }
            }
        }
    }

    private function bfsDetectCycle($obj, $path, &$visited)
    {
        $queue = [[$obj, []]];
        while (!empty($queue)) {
            list($current, $pathSoFar) = array_shift($queue);
            $id = $current->id;
            if (in_array($id, $pathSoFar)) {
                // We found a cycle
                $cycleIndex = array_search($id, $pathSoFar);
                $cyclePath = array_slice($pathSoFar, $cycleIndex);
                return $cyclePath;
            }
            $pathSoFar[] = $id;
            $visited[$id] = true;

            // Enqueue children (properties that are MemoryObjects)
            foreach ($current->properties as $prop) {
                if ($prop instanceof MemoryObject) {
                    $queue[] = [$prop, $pathSoFar];
                }
            }
        }
        return null; // no cycle found
    }

    private function memoryDiff()
    {
        $currentMem = memory_get_usage(true);
        $diff = $currentMem - $this->baselineMemory;
        if ($diff > 0) {
            $this->log("[MEM DIFF] +$diff bytes from baseline");
        } else {
            $this->log("[MEM DIFF] $diff bytes from baseline");
        }
    }

    /*************************************************************
     * "Threaded" simulation with Generators
     *************************************************************/
    public function addGenerator(\Generator $gen)
    {
        $this->generatorPool[] = $gen;
    }

    private function runGenerators()
    {
        foreach ($this->generatorPool as $idx => $gen) {
            if ($gen->valid()) {
                $gen->next();
            } else {
                unset($this->generatorPool[$idx]);
            }
        }
    }

    /*************************************************************
     * Logging Helper
     *************************************************************/
    private function log($message)
    {
        $time = microtime(true) - $this->startTime;
        $msg = sprintf("[%0.2f] %s", $time, $message) . PHP_EOL;
        file_put_contents($this->logFile, $msg, FILE_APPEND);
        echo $msg;
    }

    /*************************************************************
     * Main Profiler Loop
     *************************************************************/
    public function run($resume = false)
    {
        if ($resume) {
            $this->loadState();
        }
        // Start a repeated tick until interrupted
        $this->log("Profiler main loop started. Press Ctrl+C to stop.");

        declare(ticks=1);
        pcntl_signal(SIGINT, function () {
            $this->stop = true;
            echo "\nGraceful shutdown requested...\n";
        });

        // Start the inline console in a separate "virtual thread"
        $this->addGenerator($this->inlineConsole());

        // Example: Generate some initial objects
        for ($i = 0; $i < 5; $i++) {
            $this->createObject("InitialObj_$i");
        }

        while (!$this->stop) {
            $this->tick();
            $this->simulateLoad(); // random clones & leaks
            $this->saveState();
            sleep($this->tickInterval);
        }

        $this->log("Profiler shutting down...");
        $this->saveState();
    }

    /*************************************************************
     * Inline CLI for live queries
     *************************************************************/
    private function inlineConsole()
    {
        while (!$this->stop) {
            $line = prompt(color("Profiler CLI> ", "1;32"));
            if ($line === 'exit' || $line === 'quit') {
                $this->stop = true;
                break;
            }

            // parse commands
            $parts = explode(' ', $line);
            $cmd = strtolower($parts[0] ?? '');
            $arg = $parts[1] ?? null;

            switch ($cmd) {
                case 'inspect':
                    if (!$arg) {
                        echo "Usage: inspect ObjectLabel\n";
                        break;
                    }
                    $this->inspectByLabel($arg);
                    break;
                case 'graph':
                    if (!$arg) {
                        echo "Usage: graph ObjectID\n";
                        break;
                    }
                    $this->printGraph((int)$arg);
                    break;
                case 'zones':
                    $this->printZones();
                    break;
                case 'status':
                    $this->printStatus();
                    break;
                case 'track':
                    if (!$arg) {
                        echo "Usage: track ClassName\n";
                        break;
                    }
                    $this->createObject("Tracked_$arg");
                    break;
                default:
                    echo "Available commands: inspect, graph, zones, status, track, exit\n";
                    break;
            }
            yield;
        }
    }

    private function inspectByLabel($label)
    {
        foreach ($this->objects as $obj) {
            if ($obj->label === $label) {
                $this->log("Inspecting [ID:{$obj->id}, RefCount:{$obj->refCount}, Zone:{$obj->zone}]");
                $this->log("Properties: " . json_encode($obj->properties));
                return;
            }
        }
        echo "Object with label '$label' not found.\n";
    }

    private function printGraph($id)
    {
        if (!isset($this->objects[$id])) {
            echo "Object ID $id not found.\n";
            return;
        }
        $obj = $this->objects[$id];
        $this->log("Graph for Object [ID:$id, Label:{$obj->label}]");
        // BFS to print references
        $queue = [[$obj, 0]];
        $visited = [];
        while (!empty($queue)) {
            list($current, $level) = array_shift($queue);
            $prefix = str_repeat("  ", $level);
            echo "{$prefix}- {$current->label} (ID={$current->id}) refCount={$current->refCount}\n";
            $visited[$current->id] = true;
            foreach ($current->properties as $prop) {
                if ($prop instanceof MemoryObject && !isset($visited[$prop->id])) {
                    $queue[] = [$prop, $level + 1];
                }
            }
        }
    }

    private function printZones()
    {
        $zoneCounts = ['short-lived' => 0, 'long-lived' => 0, 'suspicious' => 0];
        foreach ($this->objects as $obj) {
            $zoneCounts[$obj->zone] = ($zoneCounts[$obj->zone] ?? 0) + 1;
        }
        echo "Zone Distribution:\n";
        foreach ($zoneCounts as $zone => $count) {
            echo "  - $zone: $count\n";
        }
    }

    private function printStatus()
    {
        echo "Tracked Objects: " . count($this->objects) . "\n";
        echo "Generators Active: " . count($this->generatorPool) . "\n";
        echo "Uptime: " . (microtime(true) - $this->startTime) . " seconds\n";
    }
}

/*************************************************************
 * Script Entry Point
 *************************************************************/
$resume = in_array('--resume', $argv);
MemoryProfiler::instance()->run($resume);
