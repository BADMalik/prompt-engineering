<?php

/**
 * Enhanced standalone PHP script to simulate memory leaks and resolve them using
 * advanced reference counting strategies. Ref mismatch bugs are fixed.
 * Script includes lifecycle ticks, pruning limits, consistency checks,
 * circular references, and memory overflow control.
 */

class MemoryObject
{
    public string $id;
    public int $memorySize; // In KB
    public array $links = [];
    public int $refCount = 0;
    public bool $isActive = true;
    public bool $isFrozen = false;
    public string $padding;

    public function __construct(string $id, int $memorySize)
    {
        $this->id = $id;
        $this->memorySize = $memorySize;
        $this->padding = str_repeat("A", $memorySize * 1024);
    }

    public function addLink(MemoryObject $obj): void
    {
        if (count($this->links) >= 15) {
            $this->links[] = new PlaceholderReference();
            return;
        }
        $this->links[] = $obj;
    }

    public function grow(): void
    {
        $this->padding .= str_repeat("B", 512);
    }

    public function isStale(int $tick, int $lastChangeTick): bool
    {
        return ($tick - $lastChangeTick) > 30;
    }
}

class PlaceholderReference {}

class MemoryGraphBuilder
{
    public array $objects = [];

    public function build(int $count = 1500): void
    {
        for ($i = 0; $i < $count; $i++) {
            $id = $this->generateId($i);
            $size = rand(1, 128);
            $this->objects[$id] = new MemoryObject($id, $size);
        }

        foreach ($this->objects as $obj) {
            $linkCount = rand(0, 10);
            for ($i = 0; $i < $linkCount; $i++) {
                $link = $this->getRandomObject();
                if ($link && $link !== $obj && !$this->hasLink($obj, $link)) {
                    $obj->addLink($link);
                }
            }
        }
    }

    private function generateId(int $seed): string
    {
        return substr(md5("MEM_" . $seed), 0, 8);
    }

    private function getRandomObject(): ?MemoryObject
    {
        return $this->objects[array_rand($this->objects)];
    }

    private function hasLink(MemoryObject $from, MemoryObject $to): bool
    {
        foreach ($from->links as $l) {
            if ($l instanceof MemoryObject && $l->id === $to->id) {
                return true;
            }
        }
        return false;
    }
}

class RefTracker
{
    private array $refs = [];
    private array $frozen = [];

    public function updateAll(array $objects): void
    {
        $this->refs = [];
        foreach ($objects as $obj) {
            foreach ($obj->links as $link) {
                if ($link instanceof MemoryObject) {
                    $this->inc($link->id);
                }
            }
        }
    }

    public function inc(string $id): void
    {
        if (isset($this->frozen[$id])) return;
        $this->refs[$id] = ($this->refs[$id] ?? 0) + 1;
    }

    public function dec(string $id): void
    {
        if (isset($this->frozen[$id])) return;
        $this->refs[$id] = max(0, ($this->refs[$id] ?? 0) - 1);
    }

    public function freeze(string $id): void
    {
        $this->frozen[$id] = true;
    }

    public function unfreeze(string $id): void
    {
        unset($this->frozen[$id]);
    }

    public function getRefCount(string $id): int
    {
        return $this->refs[$id] ?? 0;
    }

    public function isFrozen(string $id): bool
    {
        return isset($this->frozen[$id]);
    }
}

class GraphPruner
{
    public function prune(array &$objects, RefTracker $tracker, int $tick): void
    {
        $deleted = 0;
        foreach ($objects as $id => $obj) {
            if ($tracker->getRefCount($id) === 0 && !$tracker->isFrozen($id)) {
                unset($objects[$id]);
                $deleted++;
                echo "[Tick $tick] Deleted object $id\n";
                if ($deleted >= 20) return;
            }
        }
    }
}

class ConsistencyChecker
{
    public function check(array $objects, RefTracker $tracker): void
    {
        $calculatedRefs = [];
        foreach ($objects as $obj) {
            foreach ($obj->links as $link) {
                if ($link instanceof MemoryObject) {
                    $calculatedRefs[$link->id] = ($calculatedRefs[$link->id] ?? 0) + 1;
                }
            }
        }

        foreach ($objects as $id => $obj) {
            if (in_array($obj, $obj->links, true)) {
                echo "[Consistency] Self-reference in $id\n";
            }
            $actual = $tracker->getRefCount($id);
            $expected = $calculatedRefs[$id] ?? 0;
            if ($expected !== $actual) {
                echo "[Consistency] Ref mismatch on $id (expected $expected, actual $actual)\n";
            }
            if ($tracker->isFrozen($id) && $actual === 0) {
                echo "[Consistency] Frozen object with 0 refs: $id\n";
            }
        }
    }
}

// === Simulation Execution ===
$builder = new MemoryGraphBuilder();
$builder->build();

$tracker = new RefTracker();
$tracker->updateAll($builder->objects);

$pruner = new GraphPruner();
$checker = new ConsistencyChecker();

for ($tick = 1; $tick <= 100; $tick++) {
    echo "Tick $tick - Objects: " . count($builder->objects) . "\n";

    if ($tick % 10 === 0) {
        $tracker->updateAll($builder->objects);
        $checker->check($builder->objects, $tracker);
    }

    $pruner->prune($builder->objects, $tracker, $tick);

    usleep(100000); // 0.1s
}

echo "Final object count: " . count($builder->objects) . "\n";
