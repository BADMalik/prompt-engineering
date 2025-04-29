<?php

/**
 * A Single-File PHP Memory Profiler (Concept/Demonstration)
 *
 * NOTE:
 *  1) This is a conceptual implementation to illustrate how one might
 *     architect an advanced profiler in a single self-contained script.
 *  2) Many of these features (like TRUE reference counts) are not
 *     natively accessible from vanilla PHP. We simulate them here
 *     for demonstration.
 *  3) Real-world usage would require instrumentation of the Zend engine,
 *     or integration with extensions like Xdebug or custom C-level hooks.
 *  4) This script uses rudimentary data structures and manual reference
 *     tracking to simulate the desired features as best as possible.
 *  5) This script is intended to be run on the CLI. Make sure to chmod +x.
 *
 * Usage:
 *  ./memory_profiler.php [--resume]
 *
 * Features Implemented (Per Requirements):
 *  1) Time-Series Logging and Delta Analysis
 *  2) Dynamic Object Graph Visualization (BFS/DFS + cycle detection)
 *  3) Real-Time Memory Zone Classification
 *  4) In-Memory Heatmap and Memory Diff
 *  5) Shadow Cloning and Leak Injection Simulator
 *  6) Threaded Object Simulation (Generators)
 *  7) Reflection-Based Property-Level Profiling
 *  8) Extension Hook Interface
 *  9) Inline Evaluation Console
 * 10) State Persistence and Resume Mode
 *
 * DISCLAIMER: This script is purely a demonstration - actual memory profiling
 * and reference count tracking in PHP is highly implementation-dependent.
 */

////////////////////////////////////////////////////////////////////////
// Profiler Class
////////////////////////////////////////////////////////////////////////

class MemoryProfiler
{
    // Tracking arrays
    private array $objects    = [];     // [id => object]
    private array $refCounts  = [];     // [id => simulated refcount]
    private array $objectInfo = [];     // [id => metadata (creation time, label, zone, etc.)]

    // Graph adjacency list: object A -> set of object B that A references
    private array $graph = []; // [id => [id1, id2, ...]]

    // For time-series refcount logging
    private array $prevRefCounts = [];
    private array $timeSeriesLog = [];

    // For zone classification
    private array $zones = ['short' => [], 'long' => [], 'suspicious' => []];
    private int   $shortLifeThreshold = 5;   // seconds (demo)
    private int   $suspiciousThreshold = 20; // seconds (demo)

    // Hooks (Extension points)
    private array $creationHooks  = [];
    private array $deletionHooks  = [];
    private array $trackingHooks  = [];

    // Profiling intervals
    private float $lastTickTime   = 0.0;
    private float $tickInterval   = 1.0; // 1 second

    // Baseline memory usage for diffs
    private float $baselineMemory     = 0.0;
    private bool  $baselineCaptured   = false;

    // In-memory "hot" object tracking
    private array $accessFrequency = [];

    // Thread-like contexts (simulated via generators)
    private array $virtualThreads = [];

    // For persisting and resuming state
    private string $persistFile;
    private bool   $resumeMode;

    // "Leak" injection placeholders
    private array $leaks = [];

    public function __construct(string $persistFile, bool $resumeMode = false)
    {
        $this->persistFile = $persistFile;
        $this->resumeMode  = $resumeMode;
    }

    /**
     * Initialize the profiler. Attempt to resume state if requested.
     */
    public function init(): void
    {
        if ($this->resumeMode && is_file($this->persistFile)) {
            $data = unserialize(file_get_contents($this->persistFile));
            if (is_array($data)) {
                echo "[Profiler] Resuming from previous session...\n";
                $this->objects       = $data['objects'];
                $this->refCounts     = $data['refCounts'];
                $this->objectInfo    = $data['objectInfo'];
                $this->graph         = $data['graph'];
                $this->zones         = $data['zones'];
                $this->timeSeriesLog = $data['timeSeriesLog'];
                // More data can be restored as needed
            }
        }
        // Mark baseline memory usage
        $this->baselineMemory = memory_get_usage(true);
        $this->baselineCaptured = true;
    }

    /**
     * Main loop. Runs forever unless interrupted.
     * - Handles object simulation, profiling ticks, inline console.
     */
    public function runMainLoop(): void
    {
        $this->lastTickTime = microtime(true);

        // Initialize some "threads"
        $this->virtualThreads[] = $this->simulateShadowCloning();
        $this->virtualThreads[] = $this->simulateRandomLeak();
        $this->virtualThreads[] = $this->simulateObjectActivity();

        echo "[Profiler] Entering main loop. Press Ctrl+C to exit.\n";
        stream_set_blocking(STDIN, false);

        while (true) {
            $this->handleThreads();
            $this->handleConsoleInput();
            $this->maybeTick();

            // Just to not hog CPU
            usleep(50000); // 50 ms
        }
    }

    /**
     * Every second, log refcounts, do BFS for cycles, update zones, etc.
     */
    private function maybeTick(): void
    {
        $now = microtime(true);
        if (($now - $this->lastTickTime) >= $this->tickInterval) {
            $this->onTick();
            $this->lastTickTime = $now;
        }
    }

    private function onTick(): void
    {
        // 1) Time-Series Logging and Delta Analysis
        $this->logRefcountDelta();

        // 2) Detect cycles (Dynamic Object Graph Visualization)
        $cycles = $this->detectCycles();
        if (!empty($cycles)) {
            foreach ($cycles as $cycle) {
                $cycleLen = count($cycle);
                echo "\033[33m[Warning] Detected circular reference (cycle length: {$cycleLen}): " . implode('->', $cycle) . "\033[0m\n";
            }
        }

        // 3) Update memory zones
        $this->updateZones();

        // 4) Display memory usage diffs + highlight hot objects
        $this->displayMemoryDiffAndHeatmap();

        // 7) Reflection-based property checks (sample call)
        $this->inspectPropertiesAll();

        // 10) Persist state
        $this->persistState();
    }

    //////////////////////////////////////////////////////////////////////////
    // 1) Time-Series Logging and Delta Analysis
    //////////////////////////////////////////////////////////////////////////

    private function logRefcountDelta(): void
    {
        // Simulated "refCount" changes
        $deltaSummary = [];
        foreach ($this->refCounts as $id => $rc) {
            $prev = $this->prevRefCounts[$id] ?? 0;
            $delta = $rc - $prev;
            if ($delta != 0) {
                $deltaSummary[$id] = $delta;
            }
            $this->prevRefCounts[$id] = $rc;
        }
        if (!empty($deltaSummary)) {
            echo "[RefDelta] ";
            foreach ($deltaSummary as $id => $delta) {
                if ($delta > 0) {
                    echo "\033[32m{$id}(+{$delta})\033[0m ";
                } else {
                    echo "\033[31m{$id}({$delta})\033[0m ";
                }
            }
            echo "\n";
        }

        // Keep a simple time-series log
        $timestamp = time();
        $this->timeSeriesLog[$timestamp] = $this->refCounts;
    }

    //////////////////////////////////////////////////////////////////////////
    // 2) Dynamic Object Graph + cycle detection
    //////////////////////////////////////////////////////////////////////////

    private function detectCycles(): array
    {
        // BFS-based cycle detection
        // We'll do a simpler approach: for each object, do a DFS with path tracking
        $cycles = [];
        foreach ($this->graph as $id => $refs) {
            $visited = [];
            $path    = [];
            $found   = $this->dfsCycle($id, $visited, $path);
            if ($found) {
                $cycles[] = $found;
            }
        }
        return $cycles;
    }

    private function dfsCycle($node, array &$visited, array $path)
    {
        if (in_array($node, $path)) {
            // cycle found
            $cycleStart = array_search($node, $path);
            return array_slice($path, $cycleStart);
        }
        if (isset($visited[$node])) {
            return null;
        }

        $visited[$node] = true;
        $path[] = $node;

        foreach ($this->graph[$node] ?? [] as $adj) {
            $found = $this->dfsCycle($adj, $visited, $path);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    //////////////////////////////////////////////////////////////////////////
    // 3) Real-Time Memory Zone Classification
    //////////////////////////////////////////////////////////////////////////

    private function updateZones(): void
    {
        $now = time();
        foreach ($this->objectInfo as $id => $info) {
            $age = $now - $info['created_at'];

            // Re-classify
            if ($age < $this->shortLifeThreshold) {
                $this->zones['short'][$id] = true;
                unset($this->zones['long'][$id], $this->zones['suspicious'][$id]);
            } elseif ($age > $this->suspiciousThreshold) {
                $this->zones['suspicious'][$id] = true;
                unset($this->zones['short'][$id], $this->zones['long'][$id]);
                echo "\033[35m[Warning] Object $id is living suspiciously long ({$age}s).\033[0m\n";
            } else {
                $this->zones['long'][$id] = true;
                unset($this->zones['short'][$id], $this->zones['suspicious'][$id]);
            }
        }
    }

    //////////////////////////////////////////////////////////////////////////
    // 4) In-Memory Heatmap and Memory Diff
    //////////////////////////////////////////////////////////////////////////

    private function displayMemoryDiffAndHeatmap(): void
    {
        if (!$this->baselineCaptured) {
            return;
        }

        // Memory usage diff
        $currentMem  = memory_get_usage(true);
        $diff        = $currentMem - $this->baselineMemory;
        $sign        = ($diff >= 0) ? "+" : "";
        $color       = ($diff >= 0) ? "\033[31m" : "\033[32m";
        echo "[MemoryDiff] {$color}{$sign}{$diff} bytes\033[0m (current: $currentMem)\n";

        // Heatmap: show top 3 "hot" objects by access frequency
        arsort($this->accessFrequency);
        $hotSlice = array_slice($this->accessFrequency, 0, 3, true);
        echo "[Heatmap] Top 3 accessed objects: ";
        foreach ($hotSlice as $id => $freq) {
            echo "\033[36m$id($freq)\033[0m ";
        }
        echo "\n";
        // Reset frequency scores for next interval
        $this->accessFrequency = [];
    }

    //////////////////////////////////////////////////////////////////////////
    // 5) Shadow Cloning and Leak Injection Simulator (via generators)
    //////////////////////////////////////////////////////////////////////////

    private function simulateShadowCloning(): \Generator
    {
        while (true) {
            // Randomly pick an object to clone
            if (!empty($this->objects)) {
                $keys = array_keys($this->objects);
                $pick = $keys[array_rand($keys)];
                // Simulate shallow clone
                $cloned = clone $this->objects[$pick];
                $cloneId = $this->trackObject($cloned, 'CloneOf' . $pick);
                // We also track references from the clone to the original
                $this->addReference($cloneId, $pick);

                echo "[ShadowCloning] Cloned object $pick -> new object $cloneId\n";
            }
            // yield control
            yield;
            usleep(500000); // 0.5 sec
        }
    }

    private function simulateRandomLeak(): \Generator
    {
        while (true) {
            // 50% chance to inject a leak
            if (mt_rand(0, 9) > 4 && !empty($this->objects)) {
                // capture references in a closure
                $keys      = array_keys($this->objects);
                $pick      = $keys[array_rand($keys)];
                $reference = $this->objects[$pick];

                $leak = function () use ($reference) {
                    // this closure "captures" $reference
                    // preventing garbage collection
                    return spl_object_id($reference);
                };
                $this->leaks[] = $leak;

                echo "\033[33m[LeakSimulator] Injected artificial leak capturing object $pick.\033[0m\n";
            }
            yield;
            usleep(700000); // 0.7 sec
        }
    }

    //////////////////////////////////////////////////////////////////////////
    // 6) Threaded Object Simulation (simulating usage with coroutines)
    //////////////////////////////////////////////////////////////////////////

    private function simulateObjectActivity(): \Generator
    {
        // Simulate creation/deletion of objects, referencing each other
        while (true) {
            // 1) Randomly create a new object
            if (mt_rand(0, 9) > 5) {
                $newObj = new stdClass();
                $newId  = $this->trackObject($newObj, 'AutoGen');
                echo "[ObjectActivity] Created new object: $newId\n";
            }

            // 2) Randomly reference an existing object
            if (count($this->objects) > 1 && mt_rand(0, 9) > 6) {
                $ids  = array_keys($this->objects);
                $idA  = $ids[array_rand($ids)];
                $idB  = $ids[array_rand($ids)];
                if ($idA !== $idB) {
                    // Simulate referencing from A -> B
                    $this->addReference($idA, $idB);
                    echo "[ObjectActivity] $idA now references $idB\n";
                }
            }

            // 3) Occasionally remove references or untrack object
            if (!empty($this->objects) && mt_rand(0, 9) > 7) {
                $ids  = array_keys($this->objects);
                $victim = $ids[array_rand($ids)];
                $this->untrackObject($victim);
                echo "[ObjectActivity] Untracked object: $victim\n";
            }

            yield;
            usleep(300000); // 0.3 sec
        }
    }

    private function handleThreads()
    {
        foreach ($this->virtualThreads as $i => &$thread) {
            if ($thread->valid()) {
                $thread->next();
            } else {
                // restart or remove it
                $thread->rewind();
            }
        }
    }

    //////////////////////////////////////////////////////////////////////////
    // 7) Reflection-Based Property-Level Profiling
    //////////////////////////////////////////////////////////////////////////

    private function inspectPropertiesAll(): void
    {
        // Just reflect on a couple of objects randomly
        if (empty($this->objects)) {
            return;
        }

        $count = min(2, count($this->objects)); // reflect on up to 2 randomly
        $ids   = array_keys($this->objects);
        shuffle($ids);
        $slice = array_slice($ids, 0, $count);

        foreach ($slice as $id) {
            $obj = $this->objects[$id];
            $this->inspectProperties($id, $obj);
        }
    }

    private function inspectProperties($id, $obj): void
    {
        try {
            $refCls = new ReflectionObject($obj);
            $props  = $refCls->getProperties();
            echo "[Reflection] Object $id has " . count($props) . " properties.\n";
        } catch (\ReflectionException $e) {
            // ignore
        }
    }

    //////////////////////////////////////////////////////////////////////////
    // 8) Extension Hook Interface
    //////////////////////////////////////////////////////////////////////////

    public function onObjectCreate(callable $cb): void
    {
        $this->creationHooks[] = $cb;
    }

    public function onObjectDelete(callable $cb): void
    {
        $this->deletionHooks[] = $cb;
    }

    public function onObjectTrack(callable $cb): void
    {
        $this->trackingHooks[] = $cb;
    }

    //////////////////////////////////////////////////////////////////////////
    // 9) Inline Evaluation Console
    //////////////////////////////////////////////////////////////////////////

    private function handleConsoleInput(): void
    {
        $cmd = fgets(STDIN);
        if ($cmd === false) {
            return; // no input available
        }
        $cmd = trim($cmd);
        if ($cmd === '') {
            return;
        }

        // parse
        $parts = explode(' ', $cmd);
        $action = $parts[0];
        $args   = array_slice($parts, 1);

        switch ($action) {
            case 'inspect':
                // usage: inspect ObjectLabel
                $label = $args[0] ?? '';
                $this->cmdInspect($label);
                break;
            case 'graph':
                // usage: graph ObjectID
                $objId = $args[0] ?? '';
                $this->cmdGraph($objId);
                break;
            case 'zones':
                // usage: zones status
                $this->cmdZonesStatus();
                break;
            case 'track':
                // usage: track new MyClass
                if (isset($args[0]) && $args[0] === 'new') {
                    $className = $args[1] ?? 'stdClass';
                    $this->cmdTrackNew($className);
                }
                break;
            default:
                echo "[Console] Unknown command: $action\n";
        }
    }

    private function cmdInspect(string $label): void
    {
        foreach ($this->objectInfo as $id => $info) {
            if ($info['label'] === $label) {
                echo "[Inspect] Found object: $id, Created at: {$info['created_at']}, Zone: {$info['zone']}\n";
                return;
            }
        }
        echo "[Inspect] No object found with label '$label'.\n";
    }

    private function cmdGraph($objId): void
    {
        if (!isset($this->graph[$objId])) {
            echo "[Graph] No such object $objId\n";
            return;
        }

        echo "Object $objId references: ";
        foreach ($this->graph[$objId] as $refId) {
            echo "$refId ";
        }
        echo "\n";
    }

    private function cmdZonesStatus(): void
    {
        echo "[Zones] Short-lived: " . count($this->zones['short'])
            . ", Long-lived: " . count($this->zones['long'])
            . ", Suspicious: " . count($this->zones['suspicious']) . "\n";
    }

    private function cmdTrackNew(string $className): void
    {
        if (!class_exists($className)) {
            echo "[Error] Class $className does not exist.\n";
            return;
        }
        $obj = new $className;
        $id  = $this->trackObject($obj, "CLI_{$className}");
        echo "[track new] Created and tracking object $id of class $className.\n";
    }

    //////////////////////////////////////////////////////////////////////////
    // 10) State Persistence
    //////////////////////////////////////////////////////////////////////////

    private function persistState(): void
    {
        $data = [
            'objects'       => $this->objects,
            'refCounts'     => $this->refCounts,
            'objectInfo'    => $this->objectInfo,
            'graph'         => $this->graph,
            'zones'         => $this->zones,
            'timeSeriesLog' => $this->timeSeriesLog,
            // more?
        ];
        file_put_contents($this->persistFile, serialize($data));
    }

    //////////////////////////////////////////////////////////////////////////
    // Internal "Object Tracking" Routines
    //////////////////////////////////////////////////////////////////////////

    /**
     * Track a new object in the profiler's data structures.
     * Simulate reference count by setting to 1.
     */
    public function trackObject(object $obj, string $label = 'Unnamed'): int
    {
        $id = spl_object_id($obj);
        $this->objects[$id]         = $obj;
        $this->refCounts[$id]       = 1; // simulate
        $this->objectInfo[$id]      = [
            'label'      => $label,
            'created_at' => time(),
            'zone'       => 'short',
        ];
        $this->graph[$id]           = [];

        // Fire create hooks
        foreach ($this->creationHooks as $hook) {
            $hook($obj, $id);
        }

        // Fire track hooks
        foreach ($this->trackingHooks as $hook) {
            $hook($obj, $id);
        }

        return $id;
    }

    /**
     * Untrack (delete) object from the profiler's data structures.
     */
    public function untrackObject(int $id): void
    {
        if (!isset($this->objects[$id])) {
            return;
        }

        // Fire deletion hooks
        foreach ($this->deletionHooks as $hook) {
            $hook($this->objects[$id], $id);
        }

        unset($this->objects[$id]);
        unset($this->refCounts[$id]);
        unset($this->objectInfo[$id]);
        unset($this->graph[$id]);

        // Also remove references to this object from others
        foreach ($this->graph as &$refs) {
            $foundKey = array_search($id, $refs, true);
            if ($foundKey !== false) {
                unset($refs[$foundKey]);
            }
        }
        foreach (['short', 'long', 'suspicious'] as $z) {
            unset($this->zones[$z][$id]);
        }
    }

    /**
     * Simulate that object A references B, thus increment B's refcount.
     */
    public function addReference(int $from, int $to): void
    {
        if (!isset($this->objects[$from]) || !isset($this->objects[$to])) {
            return;
        }
        if (!in_array($to, $this->graph[$from], false)) {
            $this->graph[$from][] = $to;
            $this->refCounts[$to]++;
        }
        // "Access frequency" metric
        $this->accessFrequency[$to] = ($this->accessFrequency[$to] ?? 0) + 1;
    }
}

////////////////////////////////////////////////////////////////////////
// MAIN SCRIPT
////////////////////////////////////////////////////////////////////////

/**
 * Entry point
 */
function main()
{
    $args = getopt('', ['resume']);
    $resume = isset($args['resume']);

    $profiler = new MemoryProfiler(__DIR__ . '/.profiler_state', $resume);

    // Example usage of hooks
    $profiler->onObjectCreate(function ($obj, $id) {
        echo "[Hook] Object $id created.\n";
    });
    $profiler->onObjectDelete(function ($obj, $id) {
        echo "[Hook] Object $id deleted.\n";
    });
    $profiler->onObjectTrack(function ($obj, $id) {
        // Could inject additional instrumentation
    });

    // Initialize
    $profiler->init();

    // (Optional) track one object to start things off
    $initObj = new stdClass();
    $profiler->trackObject($initObj, 'InitialSeed');

    // Start main loop
    $profiler->runMainLoop();
}

// Kick off
if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "This script is intended for CLI usage.\n";
}
exit(0);
