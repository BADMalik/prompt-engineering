<?php

class ObjectTracker
{
    private $objects = [];
    private $referenceCounts = [];

    public function createObject($id)
    {
        $this->objects[$id] = new stdClass();
        $this->referenceCounts[$id] = 0;
    }

    public function addReference($id)
    {
        if (isset($this->referenceCounts[$id])) {
            $this->referenceCounts[$id]++;
        }
    }

    public function removeReference($id)
    {
        if (isset($this->referenceCounts[$id])) {
            $this->referenceCounts[$id]--;
            if ($this->referenceCounts[$id] === 0) {
                $this->cleanup($id);
            }
        }
    }

    private function cleanup($id)
    {
        unset($this->objects[$id]);
        unset($this->referenceCounts[$id]);
        echo "Object $id has been cleaned up.\n";
    }

    public function detectMemoryLeaks()
    {
        $leaks = [];
        foreach ($this->referenceCounts as $id => $count) {
            if ($count > 0) {
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    public function getReferenceCounts()
    {
        return $this->referenceCounts;
    }

    public function getMemoryUsage()
    {
        return memory_get_usage();
    }
}

class MemoryLeakSimulator
{
    private $tracker;

    public function __construct()
    {
        $this->tracker = new ObjectTracker();
    }

    public function simulateMemoryLeak()
    {
        $this->tracker->createObject('A');
        $this->tracker->createObject('B');
        $this->tracker->addReference('A');
        $this->tracker->addReference('B');
        $this->tracker->objects['A']->B = $this->tracker->objects['B'];
        $this->tracker->objects['B']->A = $this->tracker->objects['A'];
    }

    public function run()
    {
        $this->simulateMemoryLeak();
        echo "Initial memory usage: " . $this->tracker->getMemoryUsage() . "\n";
        echo "Reference counts: " . print_r($this->tracker->getReferenceCounts(), true) . "\n";

        $leaks = $this->tracker->detectMemoryLeaks();
        if (!empty($leaks)) {
            echo "Detected memory leaks: " . implode(', ', $leaks) . "\n";
        }

        echo "Manually triggering garbage collection...\n";
        $this->tracker->removeReference('A');
        $this->tracker->removeReference('B');

        echo "Final memory usage: " . $this->tracker->getMemoryUsage() . "\n";
    }
}

$simulator = new MemoryLeakSimulator();
$simulator->run();
