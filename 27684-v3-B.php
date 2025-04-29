<?php

class ObjectManager {
    private $objects = [];
    private $referenceCounts = [];

    public function createObject($id) {
        $this->objects[$id] = new stdClass();
        $this->referenceCounts[$id] = 0;
    }

    public function referenceObject($id1, $id2) {
        if (isset($this->objects[$id1]) && isset($this->objects[$id2])) {
            $this->objects[$id1]->$id2 = $this->objects[$id2];
            $this->referenceCounts[$id2]++;
        }
    }

    public function dereferenceObject($id1, $id2) {
        if (isset($this->objects[$id1]) && isset($this->objects[$id2])) {
            unset($this->objects[$id1]->$id2);
            $this->referenceCounts[$id2]--;
            if ($this->referenceCounts[$id2] === 0) {
                unset($this->objects[$id2]);
                unset($this->referenceCounts[$id2]);
            }
        }
    }

    public function detectMemoryLeaks() {
        $leaks = [];
        foreach ($this->referenceCounts as $id => $count) {
            if ($count > 0 && $this->isObjectOrphaned($id)) {
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    private function isObjectOrphaned($id) {
        $queue = [$id];
        $visited = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            foreach ($this->objects as $objId => $obj) {
                if (isset($obj->$current)) {
                    $queue[] = $objId;
                }
            }
        }
        return !isset($visited['root']);
    }

    public function getReferenceCounts() {
        return $this->referenceCounts;
    }

    public function getObjects() {
        return $this->objects;
    }

    public function manualGarbageCollection() {
        foreach ($this->referenceCounts as $id => $count) {
            if ($count === 0) {
                unset($this->objects[$id]);
                unset($this->referenceCounts[$id]);
            }
        }
    }
}

class MemoryLeakSimulator {
    private $objectManager;

    public function __construct() {
        $this->objectManager = new ObjectManager();
        $this->objectManager->createObject('root');
    }

    public function simulateMemoryLeak() {
        $this->objectManager->createObject('A');
        $this->objectManager->createObject('B');
        $this->objectManager->referenceObject('root', 'A');
        $this->objectManager->referenceObject('A', 'B');
        $this->objectManager->referenceObject('B', 'A');
    }

    public function monitor() {
        echo "Reference Counts: " . print_r($this->objectManager->getReferenceCounts(), true);
        echo "Detected Memory Leaks: " . print_r($this->objectManager->detectMemoryLeaks(), true);
    }

    public function triggerGarbageCollection() {
        $this->objectManager->manualGarbageCollection();
        echo "Manual garbage collection triggered.\n";
    }
}

$simulator = new MemoryLeakSimulator();
$simulator->simulateMemoryLeak();
$simulator->monitor();
$simulator->triggerGarbageCollection();
$simulator->monitor();

?>
