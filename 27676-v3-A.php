<?php
/**
 * Memory Leak Simulation & Reference Counting Demo
 *
 * A standalone PHP script (CLI-based) that simulates a memory leak scenario
 * by creating circular references between objects, manually tracks reference
 * counts, detects potential leaks, and allows garbage collection using a
 * custom reference counting strategy.
 *
 * Usage:
 *   php memory_leak_simulation.php
 *
 * Commands (interactive):
 *   create <count>  - Create <count> new objects
 *   link <id1> <id2> - Create a reference from object <id1> to object <id2>
 *   unlink <id1> <id2> - Remove the reference from object <id1> to object <id2>
 *   show            - Display the current status of all objects and references
 *   detect          - Detect any memory leaks
 *   gc              - Manually trigger garbage collection
 *   leakdemo        - Quickly demonstrate a sample memory leak scenario
 *   exit            - Quit the application
 */

// --------------------------------------------------------
// Classes
// --------------------------------------------------------

/**
 * Class MemoryManagedObject
 * Represents an object that can reference other objects.
 */
class MemoryManagedObject
{
    private static int $nextId = 0;  // Used to assign unique IDs.
    private int $id;
    private int $referenceCount = 0;
    private array $references = [];  // Holds references (object IDs) to other objects.

    public function __construct()
    {
        $this->id = ++self::$nextId;
        MemoryManager::registerObject($this);
    }

    /**
     * Get the unique ID of this object.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Increase this object's reference count.
     */
    public function incrementReferenceCount(): void
    {
        $this->referenceCount++;
    }

    /**
     * Decrease this object's reference count.
     */
    public function decrementReferenceCount(): void
    {
        $this->referenceCount--;
    }

    /**
     * Get the current reference count.
     */
    public function getReferenceCount(): int
    {
        return $this->referenceCount;
    }

    /**
     * Create/reference another object by ID.
     */
    public function addReference(MemoryManagedObject $object): void
    {
        $id = $object->getId();
        if (!in_array($id, $this->references, true)) {
            $this->references[] = $id;
            MemoryManager::incrementRef($object);
        }
    }

    /**
     * Remove a reference to another object by ID.
     */
    public function removeReference(MemoryManagedObject $object): void
    {
        $id = $object->getId();
        $index = array_search($id, $this->references, true);
        if ($index !== false) {
            unset($this->references[$index]);
            $this->references = array_values($this->references); // re-index array
            MemoryManager::decrementRef($object);
        }
    }

    /**
     * Return the list of object IDs this object references.
     */
    public function getReferences(): array
    {
        return $this->references;
    }
}

/**
 * Class MemoryManager
 * Provides static functions to manage objects, track references, detect leaks, and perform cleanup.
 */
class MemoryManager
{
    /** @var MemoryManagedObject[] */
    private static array $objectRegistry = []; // id => MemoryManagedObject

    /**
     * Registers a newly created object in the manager.
     */
    public static function registerObject(MemoryManagedObject $object): void
    {
        self::$objectRegistry[$object->getId()] = $object;
        // Initially, let's consider a newly created object is "rooted" externally.
        // So we manually set its reference count to 1 for demonstration.
        $object->incrementReferenceCount();
    }

    /**
     * Unregister an object, removing it from the manager.
     */
    private static function unregisterObject(int $id): void
    {
        unset(self::$objectRegistry[$id]);
    }

    /**
     * Increment the reference count of an object.
     */
    public static function incrementRef(MemoryManagedObject $object): void
    {
        $object->incrementReferenceCount();
    }

    /**
     * Decrement the reference count of an object. If it hits zero, free it.
     */
    public static function decrementRef(MemoryManagedObject $object): void
    {
        $object->decrementReferenceCount();
    }

    /**
     * Look up an object by its ID.
     */
    public static function getObjectById(int $id): ?MemoryManagedObject
    {
        return self::$objectRegistry[$id] ?? null;
    }

    /**
     * Trigger a manual garbage collection based on the custom reference counts.
     * Any object with referenceCount <= 0 is considered unreferenced and will be destroyed.
     */
    public static function collectGarbage(): void
    {
        $collected = true;
        while ($collected) {
            $collected = false;
            foreach (self::$objectRegistry as $id => $object) {
                if ($object->getReferenceCount() <= 0) {
                    // Must also remove the object from any referencing objects.
                    self::destroyObject($object);
                    $collected = true; // We changed something, so keep checking in case more got freed.
                    break;
                }
            }
        }
    }

    /**
     * Destroy an object, removing it and breaking references.
     */
    private static function destroyObject(MemoryManagedObject $object): void
    {
        $id = $object->getId();
        // Remove it from the manager
        self::unregisterObject($id);

        // Remove references from all other objects pointing to this one
        foreach (self::$objectRegistry as $otherObj) {
            $refs = $otherObj->getReferences();
            if (in_array($id, $refs, true)) {
                $otherObj->removeReference($object);
            }
        }
    }

    /**
     * Detect memory leaks by identifying objects that are not root-referenced
     * but still have references > 0 (indicating a circular reference or orphan).
     *
     * In this simplistic model, we assume:
     * - An object that was "created" has an initial external reference.
     * - If we remove that external reference, the object's reference count
     *   should go down. If it remains > 0 but there's no external link,
     *   it's a likely leak (circular reference).
     *
     * This function tries to detect such situations.
     */
    public static function detectLeaks(): array
    {
        $leaks = [];
        foreach (self::$objectRegistry as $id => $object) {
            // We consider an object a "leak" if:
            // - It has a referenceCount > 0
            // - And there's no "external" root reference. In this simple approach,
            //   if the object was created and we never explicitly decremented
            //   its external root reference, it would remain 1 or more. If we've
            //   called "unlinkroot" or something similar, that external reference
            //   might be gone. For demonstration, let's say if referenceCount > 1,
            //   it might be part of a cycle. If referenceCount is 1, we assume
            //   that's the external reference. If referenceCount > 1, there's
            //   some internal referencing going on. We'll highlight it.
            //
            // Note: This is a naive approach, but enough to illustrate the concept.
            if ($object->getReferenceCount() > 1) {
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    /**
     * Show summary of all managed objects and references.
     */
    public static function showStatus(): void
    {
        echo PHP_EOL . "Current Objects in Memory:" . PHP_EOL;
        foreach (self::$objectRegistry as $object) {
            echo "  [Obj#{$object->getId()}] RefCount={$object->getReferenceCount()} => References [";
            echo implode(', ', $object->getReferences());
            echo "]" . PHP_EOL;
        }
    }

    /**
     * Create a specified number of objects.
     * Each new object has an initial "external" reference count of 1 by design.
     */
    public static function createObjects(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            new MemoryManagedObject();
        }
    }

    /**
     * Simulate removing the external user reference from an object,
     * effectively decrementing its reference count by 1.
     */
    public static function removeExternalReference(int $id): void
    {
        $object = self::getObjectById($id);
        if ($object) {
            // Decrement once for the external reference
            self::decrementRef($object);
            echo "Removed external reference from object {$id}." . PHP_EOL;
        } else {
            echo "Object {$id} not found." . PHP_EOL;
        }
    }

    /**
     * Creates a simple circular reference scenario between two new objects
     * to demonstrate memory leak detection and garbage collection.
     */
    public static function leakDemo(): void
    {
        // Create two objects
        $a = new MemoryManagedObject();
        $b = new MemoryManagedObject();

        // Link them circularly
        $a->addReference($b);
        $b->addReference($a);

        echo "Leak Demo Created: Object#{$a->getId()} <--> Object#{$b->getId()}" . PHP_EOL;
        echo "Now removing external references from both to illustrate a leak scenario." . PHP_EOL;

        // Remove external references (the "root" references)
        MemoryManager::removeExternalReference($a->getId());
        MemoryManager::removeExternalReference($b->getId());
    }
}

// --------------------------------------------------------
// CLI INTERFACE
// --------------------------------------------------------

/**
 * Simple CLI loop to manage user commands.
 */
function mainLoop()
{
    echo "==========================================" . PHP_EOL;
    echo " Memory Leak Simulation & Reference Counts" . PHP_EOL;
    echo "==========================================" . PHP_EOL;
    echo "Type 'help' to see available commands." . PHP_EOL;

    while (true) {
        echo PHP_EOL . "> ";
        $input = trim(fgets(STDIN));
        if (!$input) {
            continue;
        }

        $parts = explode(' ', $input);
        $command = strtolower($parts[0]);

        switch ($command) {
            case 'help':
                showHelp();
                break;

            case 'create':
                if (isset($parts[1]) && is_numeric($parts[1])) {
                    $count = (int)$parts[1];
                    MemoryManager::createObjects($count);
                    echo "Created {$count} object(s)." . PHP_EOL;
                } else {
                    echo "Usage: create <count>" . PHP_EOL;
                }
                break;

            case 'link':
                if (isset($parts[1], $parts[2]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $id1 = (int)$parts[1];
                    $id2 = (int)$parts[2];
                    $obj1 = MemoryManager::getObjectById($id1);
                    $obj2 = MemoryManager::getObjectById($id2);
                    if ($obj1 && $obj2) {
                        $obj1->addReference($obj2);
                        echo "Linked Object#{$id1} --> Object#{$id2}" . PHP_EOL;
                    } else {
                        echo "Invalid object ID(s)." . PHP_EOL;
                    }
                } else {
                    echo "Usage: link <id1> <id2>" . PHP_EOL;
                }
                break;

            case 'unlink':
                if (isset($parts[1], $parts[2]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $id1 = (int)$parts[1];
                    $id2 = (int)$parts[2];
                    $obj1 = MemoryManager::getObjectById($id1);
                    $obj2 = MemoryManager::getObjectById($id2);
                    if ($obj1 && $obj2) {
                        $obj1->removeReference($obj2);
                        echo "Unlinked Object#{$id1} --> Object#{$id2}" . PHP_EOL;
                    } else {
                        echo "Invalid object ID(s)." . PHP_EOL;
                    }
                } else {
                    echo "Usage: unlink <id1> <id2>" . PHP_EOL;
                }
                break;

            case 'removeext':
                // Removes the external reference from the object
                if (isset($parts[1]) && is_numeric($parts[1])) {
                    $id = (int)$parts[1];
                    MemoryManager::removeExternalReference($id);
                } else {
                    echo "Usage: removeext <id>" . PHP_EOL;
                }
                break;

            case 'show':
                MemoryManager::showStatus();
                break;

            case 'detect':
                $leaks = MemoryManager::detectLeaks();
                if ($leaks) {
                    echo "Potential Memory Leaks Detected in Object IDs: " . implode(', ', $leaks) . PHP_EOL;
                } else {
                    echo "No obvious leaks detected (naive check)." . PHP_EOL;
                }
                break;

            case 'gc':
                MemoryManager::collectGarbage();
                echo "Garbage collection triggered." . PHP_EOL;
                break;

            case 'leakdemo':
                MemoryManager::leakDemo();
                break;

            case 'exit':
                echo "Exiting..." . PHP_EOL;
                return 0;

            default:
                echo "Unknown command: {$command}. Type 'help' for a list of commands." . PHP_EOL;
        }
    }
}

/**
 * Show help commands.
 */
function showHelp()
{
    echo <<<EOT
Available Commands:
  help
      Display this help message.

  create <count>
      Create <count> new objects. 
      Each new object has an external reference count of 1.

  link <id1> <id2>
      Add a reference from Object #<id1> to Object #<id2>.

  unlink <id1> <id2>
      Remove a reference from Object #<id1> to Object #<id2>.

  removeext <id>
      Remove the external reference from the object #<id>.
      This simulates "the user" no longer holding onto it.

  show
      Display the current objects, their reference counts, and references.

  detect
      Attempt to detect circular reference leaks.
      Warns if reference count is > 1 (indicating potential cycles).

  gc
      Manually trigger garbage collection based on reference counts.

  leakdemo
      Demonstrate a circular reference leak scenario with two objects referencing
      each other, then removing their external references.

  exit
      Quit the program.

EOT;
}

// --------------------------------------------------------
// Entry Point
// --------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line (CLI)." . PHP_EOL;
    exit(1);
}

mainLoop();
Response Evaluation
Evaluation Form - PENDING
Accuracy *
Does the AI's response correctly and completely address the information and code requirements?

Core areas we should look for in this case:

Factual correctness
Comprehensive Answer (No missing key points)
Code syntax errors
Code functional errors


Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Making Sense / Coherence *
The LLM provides responses that fail to meaningfully engage with the user’s input. This includes:

Repeating the same information unnecessarily.
Restating the prompt without providing additional insight.
Refusing to answer valid queries without proper justification
These behaviors disrupt the conversation flow, reduce user satisfaction, and hinder productive interaction.



Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Instruction Following *
Is the provided response on-point & respects all constraints in the user prompt? Is it tailored for the User skill level?

Core areas we should look for in this case:

Comprehends and adheres to all constraints and requests of user
Addresses all the requests of the user (Exceptions will be requests that are outside the capability of the LLM. For example: Give me a sorting algorithm with O(log(n)) time OR Give me the production ready React app to track student data.)
Focus remains on the user’s request
Not too short to skip the important and helpful information
Not too verbose to include unnecessary details
Well-tailored for the skill level of the user


Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Completeness *
Evaluates whether the model’s response fully addresses all parts of the user’s prompt or task.

Key considerations:

Are all relevant points, steps, or components included?
Does the response answer the prompt in a holistic and thorough way?
Is anything important missing that the user would reasonably expect?
Annotation Levels:

No Issues:
Covers all required points completely. Nothing important is left out.
Minor Issues:
Mostly complete, with a small omission or slightly shallow explanation of one point.
Moderate Issues:
Several key aspects are missing or underdeveloped. The user gets only partial value.
Major Issues:
Critically incomplete. The response misses most of the prompt or skips essential elements, providing little to no usable value.


Efficiency & Optimality *
Is the AI's response optimal in terms of the approach, code complexity, case coverage, and the method suggested in response to the user's prompt?

Core areas we should look for in this case:

Optimality in terms of Time and Memory complexity (It is fine if assistant gives an algorithm which is efficient and used in mainstream rather than a complex algorithm which optimizes on time/memory a little bit more.)
Handles all the edge cases
Takes care of security aspect of code
During Q&A, suggest optimal answer to the user


Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Presentation/Clarity & Style *
Is the presentation of the AI's response clear and well-organized?



General presentation rules for code output:

Docstrings are not needed but complex code lines should include comments detailing logic and behavior
Test outputs include a comment with the expected response
Explanations are presented in a clear manner using bullet points.
Key terms are highlighted in bold whereas titles, articles, etc are italicized
Response doesn’t give multiple redundant code solutions to solve the same problem
Multi-line code blocks are wrapped in triple backticks with correct language specified after upper backticks to ensure proper indentation and formatting
Markdown syntax is correct and represents a proper hierarchy
White space and line breaks are used to improve readability and separate content sections
Tables are constructed with Hyphens and Pipes and are correctly lined up
Comments are clear and easily understood


﻿Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Code Quality & Execution *
Evaluate the clarity, usability of the code provided by the model. This includes how runnable, and context-aware, based on the user's prompt.



Key aspects to assess:

Executable & Functional: Can the code run successfully with minimal modification?
Integration Readiness: Can the user easily incorporate this code into their existing project?
Clarity & Readability: Are variable names, structure, and formatting conducive to understanding?
Best Practices: Does the model follow standard coding conventions and logical structuring?
Context Awareness: Does the model correctly scope its code response based on what the user is likely to need (full script vs. function vs. snippet)?


Annotation Levels:

No Issues:
Complete, runnable code block. Clean structure, follows best practices. Minimal/no edits needed.
Minor Issues:
Partial snippet (e.g., a function) meant for integration. Clear and usable with minor tweaks. May include comments like # rest of your code here.

Moderate Issues:
Incomplete code with missing parts or vague placeholders. Needs user effort to run or understand. Example: only part of a function or script.
Major Issues:
Skeleton code with empty functions or comments only. Not runnable or useful without major rewriting. Example:
Code Output after Execution *
Choose the option that best describes the code output in the model's response:



Yes: The code output included by the model in the response matches the output obtained after executing the code.

No: The code output included by the model in the response does not match the output obtained after executing the code.

N/A: No code output in the response from the model.

Other Issues *
Are there any other significant issues in the response that affect user experience but were not covered in the predefined categories?

We want you to not be confined by predefined categories while accessing the quality of response. For example: 1) Assistant forgets the context of its previous conversation. 2) The React Component provided by the assistant has very bad user experience. 3) Become overly apologetic about the things that can’t be done by the assistant.


Major Issues - Mistakes that negatively affect the user experience in significant/critical ways (The user gets little or no value, perhaps negative value).

Moderate Issues - Mistakes that partially affect the user experience. (The user gets some value, but significant improvement could be made).

Minor Issues - Mistakes that may not affect the user experience or affect it in trivial ways. (The user still gets most of the value, perhaps there’s room for improvement).

No Issues - No mistakes are made. (User gets full & optimal value)

Rating Justification *
Use this field to briefly explain why a particular rating (Major, Moderate, Minor, or No Issues) was given for each evaluation metric. Focus on specific issues, missing elements, or strengths.

Examples by Evaluation Field:
Accuracy – Explain factual or logical errors (if any):
"The model incorrectly stated that JSON is not supported in Python."
"Accurate and aligns with the user’s request."
Completeness – Note what was missing or covered well:
"Response missed key edge case around null input handling."
"Fully addressed all points from the prompt."
Code Quality & Execution – Comment on structure, clarity, and usability:
"Provided only a partial function with a vague placeholder."
"Code is clean, executable, and well-commented."
Other Issues – If marked, specify what the issue is:
"Tone was overly casual for a professional setting."
"Response included an unnecessary disclaimer that distracted from the answer."
Comments for Reference *
We will use this field to put:

Any notes that you want to convey to the reviewer. You can also include links to screenshots or a sandbox where you have tested something related to the response.
Final Score *
Rate the score as a preference for the model as a rating.

5- Exemplary

4- Good

3- Fair

2- Bad

1- Terrible


N/A
Model B: GL61TV7TN5
Token count: 755
<?php

class ObjectManager {
    private $objects = [];
    private $referenceCounts = [];

    public function createObject($id) {
        $this->objects[$id] = new stdClass();
        $this->referenceCounts[$id] = 0;
    }

    public function referenceObject($id1, $id2) {
        if (isset($this->objects[$id1]) && isset($this->objects[$id2])) {
            $this->objects[$id1]->$id2 = $this->objects[$id2];
            $this->referenceCounts[$id2]++;
        }
    }

    public function dereferenceObject($id1, $id2) {
        if (isset($this->objects[$id1]) && isset($this->objects[$id2])) {
            unset($this->objects[$id1]->$id2);
            $this->referenceCounts[$id2]--;
            if ($this->referenceCounts[$id2] === 0) {
                unset($this->objects[$id2]);
                unset($this->referenceCounts[$id2]);
            }
        }
    }

    public function detectMemoryLeaks() {
        $leaks = [];
        foreach ($this->referenceCounts as $id => $count) {
            if ($count > 0 && $this->isObjectOrphaned($id)) {
                $leaks[] = $id;
            }
        }
        return $leaks;
    }

    private function isObjectOrphaned($id) {
        $queue = [$id];
        $visited = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;
            $visited[$current] = true;
            foreach ($this->objects as $objId => $obj) {
                if (isset($obj->$current)) {
                    $queue[] = $objId;
                }
            }
        }
        return !isset($visited['root']);
    }

    public function getReferenceCounts() {
        return $this->referenceCounts;
    }

    public function getObjects() {
        return $this->objects;
    }

    public function manualGarbageCollection() {
        foreach ($this->referenceCounts as $id => $count) {
            if ($count === 0) {
                unset($this->objects[$id]);
                unset($this->referenceCounts[$id]);
            }
        }
    }
}

class MemoryLeakSimulator {
    private $objectManager;

    public function __construct() {
        $this->objectManager = new ObjectManager();
        $this->objectManager->createObject('root');
    }

    public function simulateMemoryLeak() {
        $this->objectManager->createObject('A');
        $this->objectManager->createObject('B');
        $this->objectManager->referenceObject('root', 'A');
        $this->objectManager->referenceObject('A', 'B');
        $this->objectManager->referenceObject('B', 'A');
    }

    public function monitor() {
        echo "Reference Counts: " . print_r($this->objectManager->getReferenceCounts(), true);
        echo "Detected Memory Leaks: " . print_r($this->objectManager->detectMemoryLeaks(), true);
    }

    public function triggerGarbageCollection() {
        $this->objectManager->manualGarbageCollection();
        echo "Manual garbage collection triggered.\n";
    }
}

$simulator = new MemoryLeakSimulator();
$simulator->simulateMemoryLeak();
$simulator->monitor();
$simulator->triggerGarbageCollection();
$simulator->monitor();

?>