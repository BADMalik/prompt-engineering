<?php

interface MemoryDefragmenter
{
    public function startTracking(): void;
    public function recordAllocation(string $label, array &$reference): void;
    public function optimizeMemory(): void;
    public function getMemoryReport(): void;
}

class ConsoleLogger
{
    public function log(string $message, string $level = 'info'): void
    {
        $color = match ($level) {
            'info' => "\033[32m",
            'warning' => "\033[33m",
            'error' => "\033[31m",
            default => "\033[0m"
        };
        echo $color . "[{$level}] " . $message . "\033[0m\n";
    }
}

class MemoryTracker
{
    private array $snapshots = [];

    public function track(string $label, array $array): void
    {
        $this->snapshots[$label][] = count($array);
    }

    public function getFragmentationScore(string $label): float
    {
        $history = $this->snapshots[$label] ?? [];
        if (count($history) < 2) return 0.0;
        $max = max($history);
        $current = end($history);
        return $max === 0 ? 0.0 : ($max - $current) / $max;
    }
}

class Reallocator
{
    private ConsoleLogger $logger;

    public function __construct(ConsoleLogger $logger)
    {
        $this->logger = $logger;
    }

    public function reallocate(array &$ref): void
    {
        $before = memory_get_usage(true);
        $ref = array_values($ref); // reallocate
        $after = memory_get_usage(true);
        $this->logger->log("Reallocated array. Memory before: {$before}, after: {$after}", 'warning');
    }
}

class ArrayMemoryManager implements MemoryDefragmenter
{
    private array $registry = [];
    private MemoryTracker $tracker;
    private Reallocator $reallocator;
    private ConsoleLogger $logger;

    public function __construct()
    {
        $this->tracker = new MemoryTracker();
        $this->logger = new ConsoleLogger();
        $this->reallocator = new Reallocator($this->logger);
    }

    public function startTracking(): void
    {
        $this->logger->log("Started memory tracking.");
    }

    public function recordAllocation(string $label, array &$reference): void
    {
        $this->tracker->track($label, $reference);
        $this->registry[$label] = [
            'ref' => &$reference,
            'allocated_at' => time(),
            'last_access' => time(),
        ];
        $this->logger->log("Recorded allocation for '{$label}' with size: " . count($reference));
    }

    public function optimizeMemory(): void
    {
        foreach ($this->registry as $label => &$meta) {
            $fragScore = $this->tracker->getFragmentationScore($label);
            if ($fragScore > 0.25) {
                $this->logger->log("High fragmentation ({$fragScore}) detected in '{$label}'", 'warning');
                $this->reallocator->reallocate($meta['ref']);
            }
        }
    }

    public function getMemoryReport(): void
    {
        $this->logger->log("Memory Report:", 'info');
        foreach ($this->registry as $label => $meta) {
            $fragScore = $this->tracker->getFragmentationScore($label);
            $this->logger->log("[{$label}] Fragmentation: {$fragScore}");
        }
    }
}

// === Test Harness ===
$manager = new ArrayMemoryManager();
$manager->startTracking();

$growing = [];
$shrinking = range(1, 1000);
$random = range(1, 500);

for ($i = 1; $i <= 20; $i++) {
    echo "\n--- Second {$i} ---\n";

    // Simulate activity
    $growing[] = str_repeat("x", rand(1000, 5000));
    array_pop($shrinking);
    if (rand(0, 1)) {
        $random[] = rand();
    } else {
        array_pop($random);
    }

    $manager->recordAllocation('growing', $growing);
    $manager->recordAllocation('shrinking', $shrinking);
    $manager->recordAllocation('random', $random);

    $manager->optimizeMemory();
    $manager->getMemoryReport();
    sleep(1);
}
