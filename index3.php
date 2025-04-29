<?php

/**
 * Example usage / CLI entry point
 *
 * Run "php leaklog.php --report-format=json" to dump JSON output.
 */
if (PHP_SAPI === 'cli') {
    // Simple command-line argument handling (no third-party libs).
    $reportFormat = 'text';
    if (in_array('--report-format=json', $argv ?? [])) {
        $reportFormat = 'json';
    }

    // Quick demo objects
    class Dummy {}
    $logger = new ObjectLeakLogger();
    $obj = new Dummy();
    $logger->register($obj, 'DummyInstance');

    for ($i = 0; $i < 5; $i++) {
        $logger->snapshot();
        usleep(100000);
    }

    echo $logger->generateReport($reportFormat) . PHP_EOL;
    exit(0);
}

/**
 * Main memory-leak logger class.
 */
class ObjectLeakLogger
{
    /**
     * We store a WeakReference rather than a strong reference to avoid artificially
     * increasing the object's refcount or preventing its garbage collection.
     *
     * @var array<int, array{weakRef: \WeakReference, label: string, class: string}>
     */
    private array $objects = [];

    /**
     * Aggregated reference-count statistics to keep memory usage low.
     * Instead of storing a list of all snapshots, keep min, max, sum, and count.
     *
     * @var array<int, array{min: int, max: int, sum: int, count: int}>
     */
    private array $refStats = [];

    /**
     * Global fallback threshold for refcount alerts.
     */
    private int $defaultThreshold = 5;

    /**
     * Optional per-class thresholds. If a class is listed here, it overrides
     * the global threshold.
     *
     * @var array<string,int>
     */
    private array $classThresholds = [];

    /**
     * Optional event dispatcher callback; can be swapped out if integrating
     * with a custom event system (e.g. a framework).
     *
     * @var null|callable
     */
    private $eventDispatcher = null;

    /**
     * Register an object for leak tracking. We store only a WeakReference
     * so we do not keep the object artificially alive.
     */
    public function register(object $obj, ?string $label = null): void
    {
        $id = spl_object_id($obj);

        $this->objects[$id] = [
            'weakRef' => \WeakReference::create($obj),
            'label'   => $label ?? get_class($obj),
            'class'   => get_class($obj)
        ];

        // Initialize refcount stats
        $this->refStats[$id] = [
            'min'   => PHP_INT_MAX,
            'max'   => 0,
            'sum'   => 0,
            'count' => 0,
        ];
    }

    /**
     * Unregister an object by its spl_object_id. This allows you to
     * stop tracking if you know the object is no longer relevant.
     */
    public function unregister(int $id): void
    {
        unset($this->objects[$id], $this->refStats[$id]);
    }

    /**
     * Set a global default threshold for all objects, unless overridden
     * per-class.
     */
    public function setThreshold(int $new): void
    {
        $this->defaultThreshold = $new;
    }

    /**
     * Set a threshold for a specific class. This overrides the global threshold.
     */
    public function setClassThreshold(string $className, int $threshold): void
    {
        $this->classThresholds[$className] = $threshold;
    }

    /**
     * Collect a snapshot of current refcounts for all tracked objects.
     * Any object whose WeakReference::get() returns null is removed
     * automatically, as it has likely been garbage-collected.
     */
    public function snapshot(): void
    {
        foreach ($this->objects as $id => $info) {
            $actualObj = $info['weakRef']->get();

            // If object is GC'ed (WeakReference is null), remove it.
            if (!$actualObj) {
                $this->unregister($id);
                continue;
            }

            // Use debug_zval_dump to retrieve internal refcount output
            ob_start();
            debug_zval_dump($actualObj);
            $output = ob_get_clean() ?: '';

            // The output can vary in format; add "m" (multiline) to ensure newlines are matched.
            preg_match('/refcount\((\d+)\)/m', $output, $matches);
            $count = (int)($matches[1] ?? 0);

            // Update min/max/sum stats for memory-friendly usage
            $this->refStats[$id]['min']   = min($this->refStats[$id]['min'], $count);
            $this->refStats[$id]['max']   = max($this->refStats[$id]['max'], $count);
            $this->refStats[$id]['sum']  += $count;
            $this->refStats[$id]['count']++;

            // Determine threshold (per-class or default)
            $threshold = $this->classThresholds[$info['class']] ?? $this->defaultThreshold;
            if ($count > $threshold) {
                // Log the leak alert
                error_log("Leak alert: {$info['label']} has {$count} references (id: {$id}).");
                // Optionally dispatch an event if an external system wants it
                $this->dispatchEvent('leak_detected', [
                    'id'       => $id,
                    'refcount' => $count,
                    'label'    => $info['label'],
                ]);
            }
        }
    }

    /**
     * Generate a report of min/max/average refcounts for each tracked object.
     * This can be in either plaintext or JSON format.
     */
    public function generateReport(string $format = 'text'): string
    {
        // Build an array of data suitable for rendering as text or JSON
        $reportData = [];
        foreach ($this->objects as $id => $info) {
            $stats = $this->refStats[$id] ?? null;
            if (!$stats || $stats['count'] === 0) {
                // No snapshots captured yet
                continue;
            }

            $reportData[] = [
                'id'      => $id,
                'label'   => $info['label'],
                'class'   => $info['class'],
                'min_ref' => $stats['min'],
                'max_ref' => $stats['max'],
                'avg_ref' => $stats['sum'] / $stats['count'],
            ];
        }

        if ($format === 'json') {
            return json_encode(['report' => $reportData], JSON_PRETTY_PRINT);
        }

        // Default: plaintext
        $lines = ["Memory Leak Report:"];
        foreach ($reportData as $item) {
            $lines[] = sprintf(
                "%s (ID: %d, Class: %s) - Max: %d, Min: %d, Avg: %.2f",
                $item['label'],
                $item['id'],
                $item['class'],
                $item['max_ref'],
                $item['min_ref'],
                $item['avg_ref']
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Optional event dispatcher. If set, we call it from places like "snapshot()"
     * whenever a significant event (like a leak) occurs.
     */
    public function setEventDispatcher(callable $dispatcher): void
    {
        $this->eventDispatcher = $dispatcher;
    }

    /**
     * Dispatch an event if $this->eventDispatcher is defined.
     */
    protected function dispatchEvent(string $name, array $data = []): void
    {
        if (is_callable($this->eventDispatcher)) {
            call_user_func($this->eventDispatcher, $name, $data);
        }
    }
}
