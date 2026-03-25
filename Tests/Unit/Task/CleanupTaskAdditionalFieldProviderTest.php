<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Unit\Task;

use MFD\DeleteHidden\Task\CleanupTask;
use MFD\DeleteHidden\Task\CleanupTaskAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Scheduler;
use TYPO3\CMS\Scheduler\SchedulerManagementAction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for CleanupTaskAdditionalFieldProvider.
 *
 * Covers field generation (getAdditionalFields), input validation
 * (validateAdditionalFields), and value persistence (saveAdditionalFields).
 *
 * SchedulerModuleController is final and cannot be stubbed by PHPUnit 13.
 * We use ReflectionClass::newInstanceWithoutConstructor() to create a bare instance
 * and ReflectionProperty::setValue() to set the protected $currentAction property
 * where needed for getAdditionalFields() tests.
 */
class CleanupTaskAdditionalFieldProviderTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private CleanupTaskAdditionalFieldProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CleanupTaskAdditionalFieldProvider();
    }

    /**
     * Creates a bare SchedulerModuleController instance (no constructor) with
     * the $currentAction property set to the given action enum value.
     *
     * SchedulerModuleController is final — it cannot be stubbed with PHPUnit.
     * We bypass its complex constructor via ReflectionClass::newInstanceWithoutConstructor()
     * and set the protected property via ReflectionProperty::setValue().
     */
    private function makeSchedulerModule(SchedulerManagementAction $action): SchedulerModuleController
    {
        $rc = new \ReflectionClass(SchedulerModuleController::class);
        /** @var SchedulerModuleController $module */
        $module = $rc->newInstanceWithoutConstructor();

        $prop = $rc->getProperty('currentAction');
        $prop->setAccessible(true);
        $prop->setValue($module, $action);

        return $module;
    }

    /**
     * Creates a CleanupTask for testing saveAdditionalFields / getAdditionalFields.
     * Pre-registers Scheduler singleton and Execution stub so AbstractTask::__construct() succeeds.
     */
    private function makeTask(int $days = 90, int $limit = 500): CleanupTask
    {
        $schedulerStub = $this->createStub(Scheduler::class);
        GeneralUtility::setSingletonInstance(Scheduler::class, $schedulerStub);

        $executionStub = $this->createStub(Execution::class);
        GeneralUtility::addInstance(Execution::class, $executionStub);

        $task = new CleanupTask();
        $task->days = $days;
        $task->limit = $limit;

        return $task;
    }

    // -------------------------------------------------------------------------
    // validateAdditionalFields tests
    // -------------------------------------------------------------------------

    /**
     * Test 1 (validateDaysBelowOneReturnsFalse):
     * days='0', limit='100' → returns false.
     */
    public function testValidateDaysBelowOneReturnsFalse(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $submittedData = [
            'tx_delete_hidden_days' => '0',
            'tx_delete_hidden_limit' => '100',
        ];

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $schedulerModule));
    }

    /**
     * Test 2 (validateNonNumericDaysReturnsFalse):
     * days='abc' → returns false.
     */
    public function testValidateNonNumericDaysReturnsFalse(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $submittedData = [
            'tx_delete_hidden_days' => 'abc',
            'tx_delete_hidden_limit' => '100',
        ];

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $schedulerModule));
    }

    /**
     * Test 3 (validateLimitBelowOneReturnsFalse):
     * days='30', limit='0' → returns false.
     */
    public function testValidateLimitBelowOneReturnsFalse(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $submittedData = [
            'tx_delete_hidden_days' => '30',
            'tx_delete_hidden_limit' => '0',
        ];

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $schedulerModule));
    }

    /**
     * Test 4 (validateNonNumericLimitReturnsFalse):
     * days='30', limit='xyz' → returns false.
     */
    public function testValidateNonNumericLimitReturnsFalse(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $submittedData = [
            'tx_delete_hidden_days' => '30',
            'tx_delete_hidden_limit' => 'xyz',
        ];

        self::assertFalse($this->subject->validateAdditionalFields($submittedData, $schedulerModule));
    }

    /**
     * Test 5 (validateValidInputReturnsTrue):
     * days='30', limit='500' → returns true.
     */
    public function testValidateValidInputReturnsTrue(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $submittedData = [
            'tx_delete_hidden_days' => '30',
            'tx_delete_hidden_limit' => '500',
        ];

        self::assertTrue($this->subject->validateAdditionalFields($submittedData, $schedulerModule));
    }

    // -------------------------------------------------------------------------
    // saveAdditionalFields tests
    // -------------------------------------------------------------------------

    /**
     * Test 6 (saveSetsTaskProperties):
     * submittedData with days='45', limit='200' → $task->days === 45, $task->limit === 200.
     */
    public function testSaveSetsTaskProperties(): void
    {
        $submittedData = [
            'tx_delete_hidden_days' => '45',
            'tx_delete_hidden_limit' => '200',
        ];

        $task = $this->makeTask();
        $this->subject->saveAdditionalFields($submittedData, $task);

        self::assertSame(45, $task->days);
        self::assertSame(200, $task->limit);
    }

    // -------------------------------------------------------------------------
    // getAdditionalFields tests
    // -------------------------------------------------------------------------

    /**
     * Test 7 (getAdditionalFieldsReturnsDefaultsForAddAction):
     * ADD action → returned HTML contains value="90" for days and value="500" for limit.
     */
    public function testGetAdditionalFieldsReturnsDefaultsForAddAction(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::ADD);
        $taskInfo = [];
        $task = $this->makeTask();
        $fields = $this->subject->getAdditionalFields($taskInfo, $task, $schedulerModule);

        self::assertArrayHasKey('task_delete_hidden_days', $fields);
        self::assertArrayHasKey('task_delete_hidden_limit', $fields);

        self::assertStringContainsString('value="90"', $fields['task_delete_hidden_days']['code']);
        self::assertStringContainsString('value="500"', $fields['task_delete_hidden_limit']['code']);
    }

    /**
     * Test 8 (getAdditionalFieldsReadsTaskValuesForEditAction):
     * EDIT action with task->days=45, task->limit=200 →
     * returned HTML contains value="45" and value="200".
     */
    public function testGetAdditionalFieldsReadsTaskValuesForEditAction(): void
    {
        $schedulerModule = $this->makeSchedulerModule(SchedulerManagementAction::EDIT);
        $task = $this->makeTask(days: 45, limit: 200);
        $taskInfo = [];
        $fields = $this->subject->getAdditionalFields($taskInfo, $task, $schedulerModule);

        self::assertStringContainsString('value="45"', $fields['task_delete_hidden_days']['code']);
        self::assertStringContainsString('value="200"', $fields['task_delete_hidden_limit']['code']);
    }
}
