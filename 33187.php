<?php

/**
 * Standalone PHP script to simulate a dynamic array with auto-resizing.
 * Includes insert, delete, retrieve, and print operations with internal memory optimization.
 */

class DynamicArray
{
    private $array;
    private $size;
    private $capacity;
    private $growthFactor;
    private $resizeCount;

    public function __construct($initialCapacity = 4, $growthFactor = 2)
    {
        $this->capacity = $initialCapacity;
        $this->growthFactor = $growthFactor;
        $this->array = array_fill(0, $this->capacity, null);
        $this->size = 0;
        $this->resizeCount = 0;
    }

    // Inserts value at the end and resizes if needed
    public function insert($value)
    {
        if ($this->size >= $this->capacity) {
            $this->resize();
        }
        $this->array[$this->size] = $value;
        $this->size++;
    }

    // Deletes element at a specific index and shifts remaining items
    public function delete($index)
    {
        if ($index < 0 || $index >= $this->size) {
            throw new Exception("Index out of bounds for deletion.");
        }

        for ($i = $index; $i < $this->size - 1; $i++) {
            $this->array[$i] = $this->array[$i + 1];
        }

        $this->array[$this->size - 1] = null;
        $this->size--;
    }

    // Retrieves element at specific index
    public function get($index)
    {
        if ($index < 0 || $index >= $this->size) {
            throw new Exception("Index out of bounds for retrieval.");
        }
        return $this->array[$index];
    }

    // Prints the current array contents
    public function printArray()
    {
        echo "Array contents: ";
        for ($i = 0; $i < $this->size; $i++) {
            echo $this->array[$i] . " ";
        }
        echo PHP_EOL;
    }

    // Resizes internal array using growth factor
    private function resize()
    {
        $newCapacity = (int)($this->capacity * $this->growthFactor);
        $newArray = array_fill(0, $newCapacity, null);

        for ($i = 0; $i < $this->size; $i++) {
            $newArray[$i] = $this->array[$i];
        }

        $this->array = $newArray;
        $this->capacity = $newCapacity;
        $this->resizeCount++;
    }

    // Logs performance and memory usage
    public function logStats()
    {
        echo "Size: $this->size\n";
        echo "Capacity: $this->capacity\n";
        echo "Total Resizes: $this->resizeCount\n";
        echo "Estimated Memory Usage: " . memory_get_usage(true) . " bytes\n";
    }
}

// CLI Menu
function cliMenu()
{
    $array = new DynamicArray();

    while (true) {
        echo "\nChoose an action:\n";
        echo "1. Insert element\n";
        echo "2. Delete element at index\n";
        echo "3. Retrieve element at index\n";
        echo "4. Print array\n";
        echo "5. Log stats\n";
        echo "6. Exit\n";
        $choice = trim(fgets(STDIN));

        try {
            switch ($choice) {
                case '1':
                    echo "Enter value to insert: ";
                    $val = trim(fgets(STDIN));
                    $array->insert($val);
                    break;
                case '2':
                    echo "Enter index to delete: ";
                    $index = (int)trim(fgets(STDIN));
                    $array->delete($index);
                    break;
                case '3':
                    echo "Enter index to retrieve: ";
                    $index = (int)trim(fgets(STDIN));
                    $value = $array->get($index);
                    echo "Value at index $index: $value\n";
                    break;
                case '4':
                    $array->printArray();
                    break;
                case '5':
                    $array->logStats();
                    break;
                case '6':
                    echo "Exiting...\n";
                    exit;
                default:
                    echo "Invalid option. Try again.\n";
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

cliMenu();
