<?php

class ObjectLeakLogger
{
    private array $objects = [];
    private array $refSnapshots = [];
    private int $defaultThreshold = 5;
    private array $classThresholds = [];
    private string $reportFormat = 'text';

    public function register(object $obj, ?string $label = null): void
    {
        $id = spl_object_id($obj);

        if (isset($this->objects[$id])) {
            return; // Avoid double registration
        }

        $this->objects[$id] = [
            'object' => $obj,
            'label' => $label ?? get_class($obj),
            'class' => get_class($obj)
        ];
    }

    public function setThreshold(int $value, ?string $className = null): void
    {
        if ($className) {
            $this->classThresholds[$className] = $value;
        } else {
            $this->defaultThreshold = $value;
        }
    }

    public function setReportFormat(string $format): void
    {
        if (!in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException("Invalid format: $format");
        }
        $this->reportFormat = $format;
    }

    public function unregisterByObject(object $obj): void
    {
        $id = spl_object_id($obj);
        unset($this->objects[$id], $this->refSnapshots[$id]);
    }

    public function snapshot(): void
    {
        foreach ($this->objects as $id => $entry) {
            if (!is_object($entry['object'])) {
                continue;
            }

            ob_start();
            debug_zval_dump($entry['object']);
            $output = ob_get_clean();

            if (!preg_match('/refcount\((\d+)\)/', $output, $matches)) {
                continue; // Output format unexpected, skip
            }

            $count = (int) $matches[1];
            $this->refSnapshots[$id][] = $count;

            $threshold = $this->classThresholds[$entry['class']] ?? $this->defaultThreshold;
            if ($count > $threshold) {
                error_log("Leak alert: {$entry['label']} has $count references (ID: $id)");
            }
        }
    }

    public function generateReport(): string
    {
        $report = [];

        foreach ($this->refSnapshots as $id => $history) {
            if (!isset($this->objects[$id])) {
                continue;
            }

            if (count($history) === 0) {
                continue;
            }

            $label = $this->objects[$id]['label'];
            $class = $this->objects[$id]['class'];

            $max = max($history);
            $min = min($history);
            $avg = round(array_sum($history) / count($history), 2);

            $entry = [
                'id' => $id,
                'label' => $label,
                'class' => $class,
                'max' => $max,
                'min' => $min,
                'avg' => $avg,
                'samples' => count($history)
            ];

            $report[] = $entry;
        }

        return $this->reportFormat === 'json'
            ? json_encode(['timestamp' => date('c'), 'report' => $report], JSON_PRETTY_PRINT)
            : $this->renderTextReport($report);
    }

    private function renderTextReport(array $entries): string
    {
        $lines = ["Memory Leak Report - " . date('Y-m-d H:i:s')];
        foreach ($entries as $e) {
            $lines[] = "{$e['label']} ({$e['class']}) [ID: {$e['id']}] → Max: {$e['max']}, Min: {$e['min']}, Avg: {$e['avg']} ({$e['samples']} samples)";
        }
        return implode("\n", $lines);
    }
}

// Simulated workload
class Dummy {}

$options = getopt("", ["report-format::"]);
$format = $options['report-format'] ?? 'text';

$logger = new ObjectLeakLogger();
$logger->setReportFormat($format);

for ($i = 0; $i < 5; $i++) {
    $obj = new Dummy();
    $logger->register($obj, "Dummy #$i");

    for ($j = 0; $j < 5; $j++) {
        $logger->snapshot();
    }

    // Simulate release
    unset($obj);
}

// Final report
echo $logger->generateReport();
