<?php

use PHPUnit\Framework\TestCase;

class MemoryLeakTest extends TestCase
{

    private $builder;
    private $tracker;
    private $pruner;
    private $checker;

    protected function setUp(): void
    {
        $this->builder = new MemoryGraphBuilder();
        $this->tracker = new RefTracker();
        $this->pruner = new GraphPruner();
        $this->checker = new ConsistencyChecker();
    }

    // Test: Object Creation
    public function testObjectCreation()
    {
        $object = new MemoryObject('test1', 10);
        $this->assertEquals('test1', $object->id);
        $this->assertEquals(10, $object->memorySize);
        $this->assertCount(0, $object->links);
    }

    // Test: Link Objects
    public function testAddLink()
    {
        $object1 = new MemoryObject('test1', 10);
        $object2 = new MemoryObject('test2', 20);
        $object1->addLink($object2);

        $this->assertCount(1, $object1->links);
        $this->assertSame($object2, $object1->links[0]);
    }

    // Test: Prevent Adding More Than 15 Links
    public function testMaxLinksLimit()
    {
        $object1 = new MemoryObject('test1', 10);
        for ($i = 0; $i < 16; $i++) {
            $object2 = new MemoryObject('test' . ($i + 2), 10);
            $object1->addLink($object2);
        }
        $this->assertCount(15, $object1->links); // Should only have 15 links
        $this->assertInstanceOf(PlaceholderReference::class, $object1->links[14]);
    }

    // Test: Reference Counting Increment and Decrement
    public function testReferenceCounting()
    {
        $object1 = new MemoryObject('test1', 10);
        $object2 = new MemoryObject('test2', 20);
        $object1->addLink($object2);

        $this->tracker->updateAll([$object1, $object2]);
        $this->tracker->inc('test2');
        $this->tracker->dec('test2');

        $this->assertEquals(1, $this->tracker->getRefCount('test2'));
        $this->assertEquals(0, $this->tracker->getRefCount('test1'));
    }

    // Test: Pruning Objects with Zero References
    public function testPruneObjects()
    {
        $object1 = new MemoryObject('test1', 10);
        $object2 = new MemoryObject('test2', 20);

        $this->builder->objects['test1'] = $object1;
        $this->builder->objects['test2'] = $object2;

        $this->tracker->inc('test1');
        $this->tracker->dec('test1');

        $this->pruner->prune($this->builder->objects, $this->tracker, 1);

        $this->assertCount(1, $this->builder->objects); // Only test2 should remain
    }

    // Test: Consistency Check for Self-Reference
    public function testConsistencySelfReference()
    {
        $object = new MemoryObject('test1', 10);
        $object->addLink($object);

        $this->builder->objects['test1'] = $object;
        $this->tracker->updateAll([$object]);

        $this->checker->check($this->builder->objects, $this->tracker);

        $this->expectOutputString("[Consistency] Self-reference in test1\n");
    }

    // Test: Consistency Check for Reference Mismatch
    public function testConsistencyRefMismatch()
    {
        $object1 = new MemoryObject('test1', 10);
        $object2 = new MemoryObject('test2', 20);
        $object1->addLink($object2);

        $this->builder->objects['test1'] = $object1;
        $this->builder->objects['test2'] = $object2;
        $this->tracker->updateAll([$object1, $object2]);

        $this->checker->check($this->builder->objects, $this->tracker);

        $this->expectOutputString("[Consistency] Ref mismatch on test2 (expected 1, actual 0)\n");
    }

    // Test: Freeze and Unfreeze an Object
    public function testFreezeUnfreeze()
    {
        $object = new MemoryObject('test1', 10);

        $this->tracker->freeze('test1');
        $this->assertTrue($this->tracker->isFrozen('test1'));

        $this->tracker->unfreeze('test1');
        $this->assertFalse($this->tracker->isFrozen('test1'));
    }

    // Test: Freeze Prevents Reference Counting
    public function testFreezePreventsReferenceCounting()
    {
        $object1 = new MemoryObject('test1', 10);
        $object2 = new MemoryObject('test2', 20);
        $object1->addLink($object2);

        $this->tracker->freeze('test2');
        $this->tracker->inc('test2'); // This should not affect frozen object

        $this->assertEquals(0, $this->tracker->getRefCount('test2'));
    }

    // Test: Object With No Links Should Be Pruned
    public function testObjectWithNoLinksPrune()
    {
        $object = new MemoryObject('test1', 10);
        $this->builder->objects['test1'] = $object;

        $this->tracker->updateAll([$object]);
        $this->pruner->prune($this->builder->objects, $this->tracker, 1);

        $this->assertEmpty($this->builder->objects); // The object should be pruned
    }

    // Test: Large Object Graph
    public function testLargeObjectGraph()
    {
        $this->builder->build(1000);

        $this->assertCount(1000, $this->builder->objects);
    }

    // Test: Pruning After Multiple Ticks
    public function testPruningAfterMultipleTicks()
    {
        $this->builder->build(100);
        for ($tick = 1; $tick <= 10; $tick++) {
            $this->pruner->prune($this->builder->objects, $this->tracker, $tick);
        }

        $this->assertLessThanOrEqual(100, count($this->builder->objects));
    }
}
