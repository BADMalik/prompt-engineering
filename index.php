<?php

interface MemoryDefragmenter {
    public function startTracking();
    public function recordAllocation(string $label, array &$reference);
    public function optimizeMemory();
    public function getMemoryReport(): string;
}

class MemoryTracker {
    private $arrays = [];

    public function trackArray(string $label, array &$reference) {
        $this->arrays[$label] = [
            'reference' => &$reference,
            'allocation_time' => microtime(true),
            'last_access' => microtime(true),
            'size' => count($reference),
            'fragmentation_score' => 0.0,
        ];
    }

    public function updateArray(string $label) {
        if (isset($this->arrays[$label])) {
            $this->arrays[$label]['last_access'] = microtime(true);
            $this->arrays[$label]['size'] = count($this->arrays[$label]['reference']);
            $this->calculateFragmentation($label);
        }
    }

    private function calculateFragmentation(string $label) {
        $array = &$this->arrays[$label]['reference'];
        $allocated = count($array);
        $used = array_sum(array_map('strlen', $array));
        $this->arrays[$label]['fragmentation_score'] = ($allocated - $used) / $allocated;
    }

    public function getArrays() {
        return $this->arrays;
    }
}

class Reallocator {
    public function reallocate(string $label, array &$original, MemoryTracker $memoryTracker) {
        $newArray = $original;
        $memoryTracker->trackArray($label, $newArray);
        $original = [];
        return $newArray;
    }
}

class ConsoleLogger {
    public function log(string $message, bool $warning = false) {
        $color = $warning ? "\033[31m" : "\033[32m";
        $reset = "\033[0m";
        echo $color . $message . $reset . PHP_EOL;
    }
}

class ArrayMemoryManager implements MemoryDefragmenter {
    private $memoryTracker;
    private $reallocator;
    private $logger;
    private $tracking = false;

    public function __construct() {
        $this->memoryTracker = new MemoryTracker();
        $this->reallocator = new Reallocator();
        $this->logger = new ConsoleLogger();
    }

    public function startTracking() {
        $this->tracking = true;
        $this->logger->log("Started tracking memory usage.");
    }

    public function recordAllocation(string $label, array &$reference) {
        if ($this->tracking) {
            $this->memoryTracker->trackArray($label, $reference);
            $this->logger->log("Recorded allocation for $label.");
        }
    }

    public function optimizeMemory() {
        $arrays = $this->memoryTracker->getArrays();
        foreach ($arrays as $label => $array) {
            if ($array['fragmentation_score'] > 0.25) {
                $this->logger->log("High fragmentation detected for $label. Reallocating...", true);
                $arrayReference = &$array['reference'];
                $arrayReference = $this->reallocator->reallocate($label, $arrayReference, $this->memoryTracker);
                $this->logger->log("Reallocated $label to reduce fragmentation.");
            }
        }
    }

    public function getMemoryReport(): string {
        $report = "Memory Report:\n";
        $arrays = $this->memoryTracker->getArrays();
        foreach ($arrays as $label => $array) {
            $report .= sprintf(
                "Array '%s': Size=%d, Fragmentation=%.2f%%, Last Access=%s\n",
                $label,
                $array['size'],
                $array['fragmentation_score'] * 100,
                date(DATE_ISO8601, $array['last_access'])
            );
        }
        return $report;
    }
}

// Testing Harness
$manager = new ArrayMemoryManager();
$manager->startTracking();

$array1 = [];
$array2 = [];
$array3 = [];

$manager->recordAllocation('array1', $array1);
$manager->recordAllocation('array2', $array2);
$manager->recordAllocation('array3', $array3);

for ($i = 0; $i < 20; $i++) {
    usleep(1000000); // Sleep for 1 second

    // Simulate dynamic array behavior
    $array1[] = str_repeat('a', rand(1, 100));
    array_splice($array2, rand(0, count($array2)), rand(1, 10));
    $array3 = array_fill(0, rand(10, 100), str_repeat('b', rand(1, 100)));

    $manager->recordAllocation('array1', $array1);
    $manager->recordAllocation('array2', $array2);
    $manager->recordAllocation('array3', $array3);
    $manager->optimizeMemory();

    if ($i % 5 == 0) {
        echo $manager->getMemoryReport();
    }
}

echo $manager->getMemoryReport();
?>