<?php

class MemoryProfiler {
    private $objects = [];
    private $snapshots = [];
    private $logs = [];
    private $heatmap = [];
    private $currentTick = 0;
    private $baseline = null;
    private $hooks = [];
    private $stateFile = 'profiler_state.json';

    public function __construct() {
        register_shutdown_function([$this, 'shutdown']);
        $this->loadState();
    }

    public function trackObject($object) {
        $id = spl_object_id($object);
        if (!isset($this->objects[$id])) {
            $this->objects[$id] = [
                'object' => $object,
                'refcount' => 0,
                'properties' => [],
                'creation_tick' => $this->currentTick,
                'last_access_tick' => $this->currentTick,
                'zone' => 'Short-lived'
            ];
        }
        $this->updateRefCount($id);
        $this->updateProperties($id);
        $this->callHook('track', $object);
    }

    public function updateRefCount($id) {
        $oldCount = $this->objects[$id]['refcount'];
        $newCount = $this->getRefCount($id);
        $delta = $newCount - $oldCount;
        $this->objects[$id]['refcount'] = $newCount;
        $this->objects[$id]['last_access_tick'] = $this->currentTick;

        if ($delta != 0) {
            $this->logs[$this->currentTick][$id] = $delta;
        }

        if ($this->currentTick - $this->objects[$id]['creation_tick'] > 10) {
            $this->objects[$id]['zone'] = 'Long-lived';
        }
    }

    public function updateProperties($id) {
        $reflection = new ReflectionObject($this->objects[$id]['object']);
        $properties = $reflection->getProperties();
        $this->objects[$id]['properties'] = [];

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this->objects[$id]['object']);
            $this->objects[$id]['properties'][$property->getName()] = $this->getRefCount(spl_object_id($value));
        }
    }

    public function getRefCount($id) {
        $count = 0;
        foreach ($this->objects as $obj) {
            if ($obj['object'] === $id) {
                $count++;
            }
        }
        return $count;
    }

    public function logDeltas() {
        if (!isset($this->snapshots[$this->currentTick - 1])) {
            $this->snapshots[$this->currentTick - 1] = $this->objects;
        }

        $prevTickObjects = $this->snapshots[$this->currentTick - 1];
        $currentTickObjects = $this->objects;

        foreach ($currentTickObjects as $id => $obj) {
            if (!isset($prevTickObjects[$id])) {
                $this->logs[$this->currentTick][$id] = $obj['refcount'];
            } elseif ($prevTickObjects[$id]['refcount'] != $obj['refcount']) {
                $this->logs[$this->currentTick][$id] = $obj['refcount'] - $prevTickObjects[$id]['refcount'];
            }
        }
    }

    public function detectCycles() {
        $visited = [];
        $stack = [];

        foreach ($this->objects as $id => $obj) {
            if (!isset($visited[$id])) {
                $this->dfs($id, $visited, $stack);
            }
        }
    }

    private function dfs($id, &$visited, &$stack) {
        $visited[$id] = true;
        $stack[] = $id;

        $reflection = new ReflectionObject($this->objects[$id]['object']);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this->objects[$id]['object']);

            if (is_object($value)) {
                $propId = spl_object_id($value);

                if (isset($visited[$propId]) && in_array($propId, $stack)) {
                    echo "Cycle detected involving object $id\n";
                }

                if (!isset($visited[$propId])) {
                    $this->dfs($propId, $visited, $stack);
                }
            }
        }

        array_pop($stack);
    }

    public function classifyZones() {
        foreach ($this->objects as $id => $obj) {
            if ($this->currentTick - $obj['creation_tick'] < 5) {
                $obj['zone'] = 'Short-lived';
            } elseif ($this->currentTick - $obj['creation_tick'] > 20) {
                $obj['zone'] = 'Long-lived';
            } else {
                $obj['zone'] = 'Suspicious';
            }
            $this->objects[$id] = $obj;
        }
    }

    public function generateHeatmap() {
        foreach ($this->objects as $id => $obj) {
            $this->heatmap[$id] = $obj['refcount'];
        }

        arsort($this->heatmap);
    }

    public function compareWithBaseline() {
        if ($this->baseline === null) {
            $this->baseline = $this->objects;
            return;
        }

        $diffs = [];
        foreach ($this->objects as $id => $obj) {
            if (!isset($this->baseline[$id])) {
                $diffs[$id] = $obj['refcount'];
            } elseif ($this->baseline[$id]['refcount'] != $obj['refcount']) {
                $diffs[$id] = $obj['refcount'] - $this->baseline[$id]['refcount'];
            }
        }

        return $diffs;
    }

    public function simulateLeaks() {
        foreach ($this->objects as $id => $obj) {
            if (mt_rand(0, 100) < 5) {  // 5% chance to clone
                $clone = clone $obj['object'];
                $this->trackObject($clone);
            }

            if (mt_rand(0, 100) < 5) {  // 5% chance to create a leak
                $leak = function() use ($obj) {
                    return $obj['object'];
                };
                $this->trackObject($leak);
            }
        }
    }

    public function registerHook($event, $callback) {
        $this->hooks[$event][] = $callback;
    }

    public function callHook($event, $object = null) {
        if (isset($this->hooks[$event])) {
            foreach ($this->hooks[$event] as $callback) {
                call_user_func($callback, $object);
            }
        }
    }

    public function saveState() {
        $state = [
            'objects' => $this->objects,
            'logs' => $this->logs,
            'currentTick' => $this->currentTick,
            'baseline' => $this->baseline,
        ];
        file_put_contents($this->stateFile, json_encode($state));
    }

    public function loadState() {
        if (file_exists($this->stateFile)) {
            $state = json_decode(file_get_contents($this->stateFile), true);
            $this->objects = $state['objects'];
            $this->logs = $state['logs'];
            $this->currentTick = $state['currentTick'];
            $this->baseline = $state['baseline'];
        }
    }

    public function shutdown() {
        $this->saveState();
    }

    public function run() {
        while (true) {
            $this->currentTick++;
            $this->logDeltas();
            $this->detectCycles();
            $this->classifyZones();
            $this->generateHeatmap();
            $this->compareWithBaseline();
            $this->simulateLeaks();
            $this->saveState();
            sleep(1);  // Tick every second
        }
    }

    public function console() {
        while (true) {
            $input = readline("Profiler> ");
            $command = explode(" ", $input);

            switch ($command[0]) {
                case 'inspect':
                    $this->inspectObject($command[1]);
                    break;
                case 'graph':
                    $this->graphObject($command[1]);
                    break;
                case 'zones':
                    $this->showZones();
                    break;
                case 'track':
                    $this->trackNewObject(new $command[1]());
                    break;
                default:
                    echo "Unknown command\n";
            }
        }
    }

    private function inspectObject($id) {
        if (isset($this->objects[$id])) {
            print_r($this->objects[$id]);
        } else {
            echo "Object not found\n";
        }
    }

    private function graphObject($id) {
        if (isset($this->objects[$id])) {
            $this->dfsPrint($id, []);
        } else {
            echo "Object not found\n";
        }
    }

    private function dfsPrint($id, $visited) {
        $visited[] = $id;
        echo "Object $id\n";

        $reflection = new ReflectionObject($this->objects[$id]['object']);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this->objects[$id]['object']);

            if (is_object($value)) {
                $propId = spl_object_id($value);
                if (!in_array($propId, $visited)) {
                    $this->dfsPrint($propId, $visited);
                }
            }
        }
    }

    private function showZones() {
        foreach ($this->objects as $id => $obj) {
            echo "Object $id: Zone - {$obj['zone']}\n";
        }
    }

    private function trackNewObject($object) {
        $this->trackObject($object);
    }
}

$profiler = new MemoryProfiler();

// Example usage
$obj1 = new stdClass();
$obj2 = new stdClass();
$obj1->prop = $obj2;
$obj2->prop = $obj1;  // Create a cycle

$profiler->trackObject($obj1);
$profiler->trackObject($obj2);

$profiler->run();

?>