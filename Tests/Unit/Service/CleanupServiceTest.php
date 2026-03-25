<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Unit\Service;

use MFD\DeleteHidden\Domain\CleanupResult;
use MFD\DeleteHidden\Factory\DataHandlerFactory;
use MFD\DeleteHidden\Service\CleanupService;
use MFD\DeleteHidden\Service\FinderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for CleanupService.
 *
 * CleanupService is the deletion engine that:
 * - Delegates candidate discovery to FinderService
 * - Delegates soft-deletion to DataHandler via process_cmdmap()
 * - Enforces a safety cap — aborts before any deletion when candidate count exceeds limit
 * - Supports dry-run mode — returns candidates with zero deletions and no DataHandler calls
 * - Aborts on DataHandler error — stops processing remaining records and returns partial result
 */
final class CleanupServiceTest extends UnitTestCase
{
    /**
     * Creates a DataHandler mock (with expectation support) and an empty errorLog.
     * Use this only when expects() will be configured on the returned object.
     *
     * @return MockObject&DataHandler
     */
    private function createDataHandlerMock(): MockObject&DataHandler
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->errorLog = [];

        return $dataHandler;
    }

    /**
     * Creates a DataHandler stub (no expectation support) and an empty errorLog.
     * Use this when only behaviour needs to be stubbed, not verified.
     *
     * @return Stub&DataHandler
     */
    private function createDataHandlerStub(): Stub&DataHandler
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->errorLog = [];

        return $dataHandler;
    }

    /**
     * Creates a DataHandler stub with the given errors in errorLog.
     *
     * @param list<string> $errors
     * @return Stub&DataHandler
     */
    private function createDataHandlerWithErrors(array $errors): Stub&DataHandler
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->errorLog = $errors;

        return $dataHandler;
    }

    /**
     * Creates a DataHandlerFactory stub that returns the given handlers on consecutive create() calls.
     *
     * @param DataHandler ...$handlers
     * @return Stub&DataHandlerFactory
     */
    private function createFactoryStub(DataHandler ...$handlers): Stub&DataHandlerFactory
    {
        $factory = $this->createStub(DataHandlerFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...array_values($handlers));

        return $factory;
    }

    /**
     * Builds a candidate row (the shape returned by FinderService::findCandidates).
     */
    private function makeCandidateRow(int $pid = 1, string $ctype = 'text', int $containerParent = 0): array
    {
        return [
            'pid'                 => $pid,
            'ctype'               => $ctype,
            'title'               => 'Test record',
            'tstamp'              => time() - (90 * 86400),
            'tx_container_parent' => $containerParent,
        ];
    }

    /**
     * Test 1 (dryRunReturnsNoDeletions):
     * Given FinderService returns 3 candidates and dryRun=true,
     * run() returns CleanupResult with candidateCount=3, deletedCount=0,
     * limitApplied=false, empty errors, candidates array populated.
     * DataHandler factory is never called.
     */
    public function testDryRunReturnsNoDeletions(): void
    {
        $candidates = [
            10 => $this->makeCandidateRow(),
            20 => $this->makeCandidateRow(),
            30 => $this->makeCandidateRow(),
        ];

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        /** @var MockObject&DataHandlerFactory $factory */
        $factory = $this->createMock(DataHandlerFactory::class);
        $factory->expects(self::never())->method('create');

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30, dryRun: true);

        self::assertInstanceOf(CleanupResult::class, $result);
        self::assertSame(3, $result->candidateCount);
        self::assertSame(0, $result->deletedCount);
        self::assertFalse($result->limitApplied);
        self::assertSame([], $result->errors);
        self::assertSame($candidates, $result->candidates);
    }

    /**
     * Test 2 (softDeletePassesCmdToDataHandler):
     * Given FinderService returns candidates with UIDs [10, 20],
     * run() creates a DataHandler per record via factory,
     * calls start([], ['tt_content' => [(int)$uid => ['delete' => 1]]]) and process_cmdmap() for each.
     * CleanupResult has deletedCount=2.
     */
    public function testSoftDeletePassesCmdToDataHandler(): void
    {
        $candidates = [
            10 => $this->makeCandidateRow(),
            20 => $this->makeCandidateRow(),
        ];

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        $dataHandler1 = $this->createDataHandlerMock();
        $dataHandler1->expects(self::once())
            ->method('start')
            ->with([], ['tt_content' => [10 => ['delete' => 1]]]);
        $dataHandler1->expects(self::once())->method('process_cmdmap');

        $dataHandler2 = $this->createDataHandlerMock();
        $dataHandler2->expects(self::once())
            ->method('start')
            ->with([], ['tt_content' => [20 => ['delete' => 1]]]);
        $dataHandler2->expects(self::once())->method('process_cmdmap');

        $factory = $this->createFactoryStub($dataHandler1, $dataHandler2);

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30);

        self::assertSame(2, $result->deletedCount);
        self::assertFalse($result->limitApplied);
        self::assertSame([], $result->errors);
    }

    /**
     * Test 3 (recursiveChildrenIncludedInDeletion):
     * Given FinderService returns UIDs [10, 20, 30] where 20 and 30 are children of 10,
     * all three UIDs are passed to DataHandler delete commands. deletedCount=3.
     */
    public function testRecursiveChildrenIncludedInDeletion(): void
    {
        $candidates = [
            10 => $this->makeCandidateRow(containerParent: 0),
            20 => $this->makeCandidateRow(containerParent: 10),
            30 => $this->makeCandidateRow(containerParent: 10),
        ];

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        $calledUids = [];
        $factory = $this->createStub(DataHandlerFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$calledUids): DataHandler {
            $dataHandler = $this->createStub(DataHandler::class);
            $dataHandler->errorLog = [];
            $dataHandler->method('start')->willReturnCallback(
                function (array $data, array $cmd) use (&$calledUids): void {
                    foreach ($cmd['tt_content'] ?? [] as $uid => $action) {
                        $calledUids[] = $uid;
                    }
                }
            );

            return $dataHandler;
        });

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30);

        self::assertSame(3, $result->deletedCount);
        self::assertContains(10, $calledUids);
        self::assertContains(20, $calledUids);
        self::assertContains(30, $calledUids);
    }

    /**
     * Test 4 (limitSlicesCandidates):
     * Given FinderService returns 501 candidates and limit=500,
     * run() deletes exactly 500 records and sets limitApplied=true.
     */
    public function testLimitSlicesCandidates(): void
    {
        $candidates = [];

        for ($i = 1; $i <= 501; $i++) {
            $candidates[$i] = $this->makeCandidateRow();
        }

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        $factory = $this->createStub(DataHandlerFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->createDataHandlerStub());

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30, limit: 500);

        self::assertTrue($result->limitApplied);
        self::assertSame(501, $result->candidateCount);
        self::assertSame(500, $result->deletedCount);
    }

    /**
     * Test 5 (limitWithCustomValue):
     * Given FinderService returns 100 candidates and limit=50,
     * run() deletes exactly 50 records and sets limitApplied=true.
     */
    public function testLimitWithCustomValue(): void
    {
        $candidates = [];

        for ($i = 1; $i <= 100; $i++) {
            $candidates[$i] = $this->makeCandidateRow();
        }

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        $factory = $this->createStub(DataHandlerFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->createDataHandlerStub());

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30, limit: 50);

        self::assertTrue($result->limitApplied);
        self::assertSame(100, $result->candidateCount);
        self::assertSame(50, $result->deletedCount);
    }


    /**
     * Test 7 (dataHandlerErrorAbortsRun):
     * Given FinderService returns UIDs [10, 20, 30], DataHandler for UID 20 has errorLog=['Permission denied'].
     * run() returns deletedCount=1 (only UID 10 succeeded), errors=['Permission denied'], limitApplied=false.
     */
    public function testDataHandlerErrorAbortsRun(): void
    {
        $candidates = [
            10 => $this->makeCandidateRow(),
            20 => $this->makeCandidateRow(),
            30 => $this->makeCandidateRow(),
        ];

        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn($candidates);

        $factory = $this->createFactoryStub(
            $this->createDataHandlerStub(),                          // UID 10 — success
            $this->createDataHandlerWithErrors(['Permission denied']) // UID 20 — error, UID 30 never reached
        );

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30);

        self::assertSame(1, $result->deletedCount, 'Only UID 10 should have been deleted before error aborted run');
        self::assertSame(['Permission denied'], $result->errors);
        self::assertFalse($result->limitApplied);
    }

    /**
     * Test 8 (zeroCandidatesReturnsEmptyResult):
     * Given FinderService returns empty array,
     * run() returns candidateCount=0, deletedCount=0, limitApplied=false.
     */
    public function testZeroCandidatesReturnsEmptyResult(): void
    {
        $finderService = $this->createStub(FinderService::class);
        $finderService->method('findCandidates')->willReturn([]);

        $factory = $this->createStub(DataHandlerFactory::class);

        $subject = new CleanupService($finderService, new NullLogger(), $factory);
        $result = $subject->run(days: 30);

        self::assertSame(0, $result->candidateCount);
        self::assertSame(0, $result->deletedCount);
        self::assertFalse($result->limitApplied);
        self::assertSame([], $result->errors);
    }
}
