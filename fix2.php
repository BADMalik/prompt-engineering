<?php

class ObjectProfiler
{
    private $trackedObjects = [];
    private $objectGraph = [];
    private $objectLifeCycle = [];
    private $memorySnapshots = [];
    private $zones = ['Short-lived' => [], 'Long-lived' => [], 'Suspicious' => []];
    private $hotObjects = [];
    private $startTime;

    public function __construct()
    {
        $this->startTime = time();
    }

    public function startProfiling()
    {
        echo "Memory Profiler Started...\n";
        echo "Tracking object reference counts...\n";
        $this->memorySnapshots[] = memory_get_usage();
    }

    public function trackObject($label, &$reference)
    {
        $objectId = spl_object_id($reference);
        $this->trackedObjects[$objectId] = [
            'label' => $label,
            'refCount' => $this->getReferenceCount($reference),
            'creationTime' => time(),
            'lastAccessTime' => time(),
            'memoryUsage' => memory_get_usage(),
            'creationMemoryUsage' => memory_get_usage()
        ];
        $this->objectLifeCycle[$objectId] = ['created' => time(), 'lastAccess' => time()];
        echo "Object '{$label}' tracked. Memory Usage: {$this->trackedObjects[$objectId]['memoryUsage']} bytes\n";
    }

    private function getReferenceCount($reference)
    {
        ob_start();
        debug_zval_dump($reference);
        $output = ob_get_clean();
        preg_match('/refcount: (\d+)/', $output, $matches);
        return $matches[1] ?? 0;
    }

    public function logDelta()
    {
        echo "\nLogging delta...\n";
        $currentTime = time();
        $delta = [];

        foreach ($this->trackedObjects as $objectId => $objectData) {
            $currentRefCount = $this->getReferenceCount($objectData['label']);
            $delta[$objectId] = $currentRefCount - $objectData['refCount'];

            $currentMemoryUsage = memory_get_usage();
            $memoryDelta = $currentMemoryUsage - $objectData['memoryUsage'];

            if (abs($delta[$objectId]) > 3) {
                echo "Spike detected in object '{$objectData['label']}' with delta of {$delta[$objectId]} references.\n";
            }
            if (abs($memoryDelta) > 1024) {  // 1KB memory delta for example
                echo "Memory spike detected in object '{$objectData['label']}': {$memoryDelta} bytes\n";
            }

            $this->trackedObjects[$objectId]['refCount'] = $currentRefCount;
            $this->trackedObjects[$objectId]['lastAccessTime'] = $currentTime;
            $this->trackedObjects[$objectId]['memoryUsage'] = $currentMemoryUsage;
        }

        return $delta;
    }

    public function detectCircularReferences()
    {
        echo "Detecting circular references...\n";
        foreach ($this->objectGraph as $nodeId => $edges) {
            $visited = [];
            $stack = [$nodeId];
            while ($stack) {
                $current = array_pop($stack);
                if (in_array($current, $visited)) {
                    echo "Circular reference detected in object graph at node {$nodeId}\n";
                    break;
                }
                $visited[] = $current;
                if (isset($this->objectGraph[$current])) {
                    $stack = array_merge($stack, $this->objectGraph[$current]);
                }
            }
        }
    }

    public function classifyZones()
    {
        echo "Classifying objects into memory zones...\n";
        $currentTime = time();

        foreach ($this->trackedObjects as $objectId => $objectData) {
            $age = $currentTime - $objectData['creationTime'];

            if ($age < 5) {
                $this->zones['Short-lived'][] = $objectData['label'];
                echo "Object '{$objectData['label']}' is classified as 'Short-lived' (Age: {$age}s)\n";
            } elseif ($age > 60) {
                $this->zones['Long-lived'][] = $objectData['label'];
                echo "Object '{$objectData['label']}' is classified as 'Long-lived' (Age: {$age}s)\n";
            } else {
                $this->zones['Suspicious'][] = $objectData['label'];
                echo "Object '{$objectData['label']}' is classified as 'Suspicious' (Age: {$age}s)\n";
            }

            if ($age > 30) {
                echo "Warning: Object '{$objectData['label']}' has exceeded the expected lifespan for short-lived objects.\n";
            }
        }
    }

    public function trackHotObjects()
    {
        echo "Tracking hot objects...\n";
        foreach ($this->trackedObjects as $objectId => $objectData) {
            if (!isset($this->hotObjects[$objectData['label']])) {
                $this->hotObjects[$objectData['label']] = 0;
            }
            $this->hotObjects[$objectData['label']]++;

            if ($this->hotObjects[$objectData['label']] > 3) {
                echo "Hot Object detected: '{$objectData['label']}' with {$this->hotObjects[$objectData['label']]} references.\n";
            }
        }
    }

    public function simulateCloningAndLeaks($object)
    {
        echo "Simulating object cloning and leak...\n";
        $clonedObject = clone $object;
        $this->trackObject("ClonedObject", $clonedObject);

        $leakClosure = function () use ($object) {
            return $object;
        };

        $leakClosure(); 
    }

    public function runSimulation($duration = 10)
    {
        $startTime = time();

        while (time() - $startTime < $duration) {
            echo "\n--- Profiling Interval ---\n";
            $this->logDelta();
            $this->classifyZones();
            $this->detectCircularReferences();
            $this->trackHotObjects();
            sleep(1);
        }

        echo "\n--- Final Profiling Report ---\n";
        $this->reportReferenceCounts();
    }

    public function reportReferenceCounts()
    {
        echo "Reference Counts:\n";
        foreach ($this->trackedObjects as $objectId => $objectData) {
            echo "{$objectData['label']}: {$objectData['refCount']} references | Memory Usage: {$objectData['memoryUsage']} bytes\n";
        }
    }

    public function getProfilerReport()
    {
        echo "\nProfiler Report:\n";
        $report = [];
        foreach ($this->trackedObjects as $objectId => $objectData) {
            $report[] = "Object: {$objectData['label']} | RefCount: {$objectData['refCount']} | MemoryUsage: {$objectData['memoryUsage']} bytes | Created: {$objectData['creationTime']}";
        }

        return implode("\n", $report);
    }
}

$profiler = new ObjectProfiler();
$profiler->startProfiling();

$obj1 = new stdClass();
$profiler->trackObject("Object1", $obj1);

$obj2 = new stdClass();
$profiler->trackObject("Object2", $obj2);

$profiler->runSimulation();

echo $profiler->getProfilerReport();
