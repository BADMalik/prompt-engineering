<?php

class DynamicArray
{
    private $array;
    private $size;
    private $capacity;
    private $growthFactor;
    private $resizeCount;

    public function __construct($initialCapacity = 4, $growthFactor = 2)
    {
        if (!is_int($initialCapacity) || $initialCapacity < 1) {
            throw new InvalidArgumentException("Initial capacity must be a positive integer.");
        }
        if (!is_int($growthFactor) || $growthFactor < 1) {
            throw new InvalidArgumentException("Growth factor must be a positive integer.");
        }

        $this->capacity = $initialCapacity;
        $this->growthFactor = $growthFactor;
        $this->array = array_fill(0, $initialCapacity, null);
        $this->size = 0;
        $this->resizeCount = 0;
    }

    public function insert($value)
    {
        if ($this->size >= $this->capacity) {
            $this->resize();
        }
        $this->array[$this->size] = $value;
        $this->size++;
    }

    public function delete($index)
    {
        if ($this->size == 0) {
            throw new Error("Array is empty, cannot delete.");
        }
        if ($index < 0 || $index >= $this->size) {
            throw new Error("Index out of bounds");
        }

        for ($i = $index; $i < $this->size - 1; $i++) {
            $this->array[$i] = $this->array[$i + 1];
        }

        unset($this->array[$this->size - 1]);
        $this->size--;
    }

    public function get($index)
    {
        if ($this->size == 0) {
            throw new Error("Array is empty, cannot get element.");
        }
        if ($index < 0 || $index >= $this->size) {
            throw new Error("Index out of bounds");
        }
        return $this->array[$index];
    }

    public function printArray()
    {
        echo "Array: ";
        for ($i = 0; $i < $this->size; $i++) {
            echo $this->array[$i] . ", ";
        }
        echo "\n";
    }

    private function resize()
    {
        $newCapacity = $this->capacity * $this->growthFactor;
        $newArray = array_fill(0, $newCapacity, null);

        for ($i = 0; $i < $this->size; $i++) {
            $newArray[$i] = $this->array[$i];
        }

        $this->array = $newArray;
        $this->capacity = $newCapacity;
        $this->resizeCount++;
    }

    public function logStats()
    {
        $memoryBefore = memory_get_usage(true);
        echo "Size = " . $this->size . "\n";
        echo "Cap = " . $this->capacity . "\n";
        echo "Resizes = " . $this->resizeCount . "\n";
        $memoryAfter = memory_get_usage(true);
        echo "Mem: " . ($memoryAfter - $memoryBefore) . "B\n";
    }
}

function cliMenu()
{
    $arr = new DynamicArray(3, 2);

    while (true) {
        echo "\n1. Insert 2. Delete 3. Get 4. Print 5. Log 6. Quit\n";
        $choice = trim(fgets(STDIN));

        switch ($choice) {
            case "1":
                echo "Val? ";
                $v = trim(fgets(STDIN));
                $arr->insert($v);
                break;
            case "2":
                echo "Index to delete: ";
                $i = trim(fgets(STDIN));
                try {
                    $arr->delete((int)$i);
                } catch (Error $e) {
                    echo "Error: " . $e->getMessage() . "\n";
                }
                break;
            case "3":
                echo "Index to get: ";
                $i = trim(fgets(STDIN));
                try {
                    echo $arr->get((int)$i) . "\n";
                } catch (Error $e) {
                    echo "Error: " . $e->getMessage() . "\n";
                }
                break;
            case "4":
                $arr->printArray();
                break;
            case "5":
                $arr->logStats();
                break;
            case "6":
                echo "Bye\n";
                return;
            default:
                echo "Invalid\n";
        }
    }
}

cliMenu();
