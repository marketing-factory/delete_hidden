<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Unit\Task;

use MFD\DeleteHidden\Domain\CleanupResult;
use MFD\DeleteHidden\Service\CleanupService;
use MFD\DeleteHidden\Task\CleanupTask;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Scheduler;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for CleanupTask.
 *
 * Tests the execute() delegation to CleanupService and the
 * RuntimeException error paths, plus getAdditionalInformation().
 *
 * AbstractTask::__construct() calls GeneralUtility::makeInstance(Scheduler::class)
 * and GeneralUtility::makeInstance(Execution::class). We pre-register stubs via
 * setSingletonInstance / addInstance so the constructor does not require the full DI stack.
 */
class CleanupTaskTest extends UnitTestCase
{
    /**
     * Required when using GeneralUtility::addInstance() / setSingletonInstance() in tests —
     * ensures the instance queue and singleton cache are cleared after each test.
     */
    protected bool $resetSingletonInstances = true;

    /**
     * Creates a CleanupTask, pre-registering the Scheduler and Execution stubs
     * needed by AbstractTask::__construct().
     */
    private function makeTask(int $days = 90, int $limit = 500): CleanupTask
    {
        // AbstractTask::__construct() calls makeInstance(Scheduler::class) (singleton)
        // and makeInstance(Execution::class). Register stubs before construction.
        $schedulerStub = $this->createStub(Scheduler::class);
        GeneralUtility::setSingletonInstance(Scheduler::class, $schedulerStub);

        $executionStub = $this->createStub(Execution::class);
        GeneralUtility::addInstance(Execution::class, $executionStub);

        $task = new CleanupTask();
        $task->days = $days;
        $task->limit = $limit;

        return $task;
    }

    /**
     * Test 1 (executeCallsCleanupServiceWithCorrectArguments):
     * Given days=45, limit=200 and a stub CleanupService returning a successful result,
     * execute() returns true.
     */
    public function testExecuteCallsCleanupServiceWithCorrectArguments(): void
    {
        $stub = $this->createStub(CleanupService::class);
        $stub->method('run')->willReturn(new CleanupResult(
            candidateCount: 5,
            deletedCount: 5,
            errors: [],
            limitApplied: false,
            candidates: [],
        ));

        GeneralUtility::addInstance(CleanupService::class, $stub);

        $task = $this->makeTask(days: 45, limit: 200);
        self::assertTrue($task->execute());
    }

    /**
     * Test 2 (executeReturnsTrueOnZeroCandidates):
     * Given stub returns CleanupResult with zero candidates, execute() returns true silently.
     */
    public function testExecuteReturnsTrueOnZeroCandidates(): void
    {
        $stub = $this->createStub(CleanupService::class);
        $stub->method('run')->willReturn(new CleanupResult(
            candidateCount: 0,
            deletedCount: 0,
            errors: [],
            limitApplied: false,
            candidates: [],
        ));

        GeneralUtility::addInstance(CleanupService::class, $stub);

        $task = $this->makeTask();
        self::assertTrue($task->execute());
    }

    /**
     * Test 3 (executeSucceedsWhenLimitApplied):
     * Given stub returns limitApplied=true, execute() returns true (partial run is not an error).
     */
    public function testExecuteSucceedsWhenLimitApplied(): void
    {
        $stub = $this->createStub(CleanupService::class);
        $stub->method('run')->willReturn(new CleanupResult(
            candidateCount: 600,
            deletedCount: 500,
            errors: [],
            limitApplied: true,
            candidates: [],
        ));

        GeneralUtility::addInstance(CleanupService::class, $stub);

        $task = $this->makeTask();

        self::assertTrue($task->execute());
    }

    /**
     * Test 4 (executeThrowsRuntimeExceptionOnDataHandlerErrors):
     * Given stub returns non-empty errors array, execute() throws RuntimeException
     * with message matching '/DataHandler error/'.
     */
    public function testExecuteThrowsRuntimeExceptionOnDataHandlerErrors(): void
    {
        $stub = $this->createStub(CleanupService::class);
        $stub->method('run')->willReturn(new CleanupResult(
            candidateCount: 5,
            deletedCount: 3,
            errors: ['Error in record 42'],
            limitApplied: false,
            candidates: [],
        ));

        GeneralUtility::addInstance(CleanupService::class, $stub);

        $task = $this->makeTask();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DataHandler error/');

        $task->execute();
    }

    /**
     * Test 5 (getAdditionalInformationReturnsDaysAndLimit):
     * Given days=30, limit=100, getAdditionalInformation() returns 'days: 30, limit: 100'.
     */
    public function testGetAdditionalInformationReturnsDaysAndLimit(): void
    {
        $task = $this->makeTask(days: 30, limit: 100);
        self::assertSame('days: 30, limit: 100', $task->getAdditionalInformation());
    }
}
