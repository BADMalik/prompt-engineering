<?php
class ObjectTracker
{
    private static $objects = [];
    private static $referenceCounts = [];

    public static function createObject($id)
    {
        self::$objects[$id] = new stdClass();
        self::$referenceCounts[$id] = 1;
        return self::$objects[$id];
    }

    public static function referenceObject($id, $referenceId)
    {
        if (isset(self::$objects[$id]) && isset(self::$objects[$referenceId])) {
            self::$objects[$id]->references[] = $referenceId;
            self::$referenceCounts[$referenceId]++;
        }
    }

    public static function dereferenceObject($id, $referenceId)
    {
        if (isset(self::$objects[$id]) && isset(self::$objects[$referenceId])) {
            self::$referenceCounts[$referenceId]--;
            if (self::$referenceCounts[$referenceId] == 0) {
                self::cleanupObject($referenceId);
            }
        }
    }

    private static function cleanupObject($id)
    {
        unset(self::$objects[$id]);
        unset(self::$referenceCounts[$id]);
        echo "Object $id has been cleaned up.\n";
    }

    public static function detectMemoryLeaks()
    {
        $leaks = [];
        foreach (self::$referenceCounts as $id => $count) {
            if ($count > 0 && self::isOrphaned($id)) {
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    private static function isOrphaned($id)
    {
        foreach (self::$objects as $obj) {
            if (isset($obj->references) && in_array($id, $obj->references)) {
                return false;
            }
        }
        return true;
    }

    public static function displayStatus()
    {
        echo "Object Reference Counts:\n";
        foreach (self::$referenceCounts as $id => $count) {
            echo "Object $id: $count\n";
        }

        $leaks = self::detectMemoryLeaks();
        if (!empty($leaks)) {
            echo "Detected Memory Leaks:\n";
            foreach ($leaks as $leak) {
                echo "Object $leak\n";
            }
        } else {
            echo "No memory leaks detected.\n";
        }
    }

    public static function manualGarbageCollection()
    {
        foreach (self::$referenceCounts as $id => $count) {
            if ($count == 0) {
                self::cleanupObject($id);
            }
        }
    }
}

// Simulate Memory Leak
$obj1 = ObjectTracker::createObject(1);
$obj2 = ObjectTracker::createObject(2);

ObjectTracker::referenceObject(1, 2);
ObjectTracker::referenceObject(2, 1);

// Display initial status
ObjectTracker::displayStatus();

// Dereference objects to simulate cleanup
ObjectTracker::dereferenceObject(1, 2);
ObjectTracker::dereferenceObject(2, 1);

// Display status after dereferencing
ObjectTracker::displayStatus();

// Manually trigger garbage collection
ObjectTracker::manualGarbageCollection();

// Final status
ObjectTracker::displayStatus();
