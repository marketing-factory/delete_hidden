<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use MFD\DeleteHidden\Repository\PageExclusionRepository;
use MFD\DeleteHidden\Service\FinderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for FinderService.
 *
 * FinderService identifies all tt_content records that are candidates for deletion:
 * - Directly hidden records older than N days
 * - Children of hidden containers (even if children have hidden = 0)
 * - With workspace guard (t3ver_wsid > 0 records excluded)
 * - With page exclusion filtering (content on excluded pages filtered out)
 * - With cycle detection in tx_container_parent chains
 */
final class FinderServiceTest extends UnitTestCase
{
    /**
     * Builds a QueryBuilder stub whose select/from/where/executeQuery/fetchAllAssociative
     * chain returns the given row arrays.
     *
     * Also stubs getRestrictions() -> restriction container with removeAll()/add() chain.
     */
    private function createMockQueryBuilder(array $rows): Stub&QueryBuilder
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('lt')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('uid IN (...)');

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $restrictionContainer = $this->createStub(\TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface::class);
        $restrictionContainer->method('removeAll')->willReturnSelf();
        $restrictionContainer->method('add')->willReturnSelf();

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictionContainer);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value): string {
                if (is_array($value)) {
                    return implode(',', $value);
                }

                return (string)$value;
            }
        );

        return $queryBuilder;
    }

    /**
     * Creates a ConnectionPool stub that returns the given QueryBuilder stubs in sequence.
     * When exactly one is provided it is returned for all calls.
     */
    private function createConnectionPoolStub(array $queryBuilders): Stub&ConnectionPool
    {
        $connectionPool = $this->createStub(ConnectionPool::class);

        if (count($queryBuilders) === 1) {
            $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilders[0]);
        } else {
            $connectionPool->method('getQueryBuilderForTable')
                ->willReturnOnConsecutiveCalls(...array_values($queryBuilders));
        }

        return $connectionPool;
    }

    /**
     * Test 1 (directHiddenDetection): A tt_content record with hidden=1, t3ver_wsid=0,
     * tstamp older than N days is returned.
     * Record shape: array keyed by UID with pid, ctype, title, tstamp, tx_container_parent.
     */
    public function testDirectHiddenDetection(): void
    {
        $oldTstamp = time() - (90 * 86400); // 90 days ago

        $directHiddenRows = [
            [
                'uid'                  => 42,
                'pid'                  => 1,
                'CType'                => 'textmedia',
                'header'               => 'Old hidden content',
                'tstamp'               => $oldTstamp,
                'tx_container_parent'  => 0,
            ],
        ];

        // Pass 1: direct hidden query returns one row
        $directHiddenQb = $this->createMockQueryBuilder($directHiddenRows);
        // Pass 2: children of UID 42 -> none
        $childrenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb, $childrenQb]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertArrayHasKey(42, $result, 'Hidden record older than N days must be a candidate');
        self::assertSame(1, $result[42]['pid']);
        self::assertSame('textmedia', $result[42]['ctype']);
        self::assertSame('Old hidden content', $result[42]['title']);
        self::assertSame($oldTstamp, $result[42]['tstamp']);
        self::assertSame(0, $result[42]['tx_container_parent']);
    }

    /**
     * Test 2 (notOldEnough): A hidden record whose tstamp is newer than N days is NOT returned.
     * (The direct-hidden query itself filters by tstamp < cutoff, so this returns empty.)
     */
    public function testNotOldEnough(): void
    {
        // Pass 1: direct hidden query returns no rows (DB already filters by tstamp)
        $directHiddenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertSame([], $result, 'Records newer than N days must not be returned');
    }

    /**
     * Test 3 (workspaceGuard): A hidden record with t3ver_wsid=1 is NOT returned
     * even if old enough (workspace guard is in the DB query itself).
     */
    public function testWorkspaceGuard(): void
    {
        // Pass 1: direct hidden query returns no rows (workspace records filtered at DB level)
        $directHiddenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertSame([], $result, 'Workspace records (t3ver_wsid > 0) must never be returned');
    }

    /**
     * Test 4 (pageExclusion): A hidden, old-enough record on an excluded page
     * (pid in PageExclusionRepository result) is NOT returned.
     */
    public function testPageExclusion(): void
    {
        $oldTstamp = time() - (90 * 86400);

        $directHiddenRows = [
            [
                'uid'                 => 55,
                'pid'                 => 99,
                'CType'               => 'text',
                'header'              => 'Content on excluded page',
                'tstamp'              => $oldTstamp,
                'tx_container_parent' => 0,
            ],
        ];

        // Pass 1: direct hidden query returns one row
        $directHiddenQb = $this->createMockQueryBuilder($directHiddenRows);
        // Pass 2: children of UID 55 -> none
        $childrenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb, $childrenQb]);

        // Page 99 is excluded
        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([99]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertArrayNotHasKey(55, $result, 'Content on excluded page must not be returned');
    }

    /**
     * Test 5 (containerAncestorChain): A container (hidden=1, old enough) with two children
     * (hidden=0) — all three are returned.
     */
    public function testContainerAncestorChain(): void
    {
        $oldTstamp = time() - (90 * 86400);

        // Pass 1: direct hidden — the container itself (UID 100)
        $containerRow = [
            'uid'                 => 100,
            'pid'                 => 1,
            'CType'               => 'b13-container',
            'header'              => 'Hidden container',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 0,
        ];

        // Pass 2: children of UID 100 — two children (hidden=0)
        $child1Row = [
            'uid'                 => 101,
            'pid'                 => 1,
            'CType'               => 'text',
            'header'              => 'Child 1',
            'tstamp'              => time(),
            'tx_container_parent' => 100,
        ];
        $child2Row = [
            'uid'                 => 102,
            'pid'                 => 1,
            'CType'               => 'text',
            'header'              => 'Child 2',
            'tstamp'              => time(),
            'tx_container_parent' => 100,
        ];

        // Pass 3: children of UID 101 -> none
        // Pass 4: children of UID 102 -> none

        $directHiddenQb = $this->createMockQueryBuilder([$containerRow]);
        $childrenOf100Qb = $this->createMockQueryBuilder([$child1Row, $child2Row]);
        $childrenOf101Qb = $this->createMockQueryBuilder([]);
        $childrenOf102Qb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([
            $directHiddenQb,
            $childrenOf100Qb,
            $childrenOf101Qb,
            $childrenOf102Qb,
        ]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertArrayHasKey(100, $result, 'Hidden container must be a candidate');
        self::assertArrayHasKey(101, $result, 'Child of hidden container must be a candidate');
        self::assertArrayHasKey(102, $result, 'Second child of hidden container must be a candidate');
    }

    /**
     * Test 6 (deepNestedContainer): Container A (hidden) -> child B -> grandchild C.
     * All three returned even though B and C have hidden=0.
     */
    public function testDeepNestedContainer(): void
    {
        $oldTstamp = time() - (90 * 86400);

        $containerA = [
            'uid'                 => 200,
            'pid'                 => 1,
            'CType'               => 'b13-container',
            'header'              => 'Container A',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 0,
        ];
        $childB = [
            'uid'                 => 201,
            'pid'                 => 1,
            'CType'               => 'b13-container',
            'header'              => 'Child B',
            'tstamp'              => time(),
            'tx_container_parent' => 200,
        ];
        $grandchildC = [
            'uid'                 => 202,
            'pid'                 => 1,
            'CType'               => 'text',
            'header'              => 'Grandchild C',
            'tstamp'              => time(),
            'tx_container_parent' => 201,
        ];

        // Pass 1: direct hidden -> container A
        // Pass 2: children of A -> B
        // Pass 3: children of B -> C
        // Pass 4: children of C -> none
        $directHiddenQb = $this->createMockQueryBuilder([$containerA]);
        $childrenOfAQb = $this->createMockQueryBuilder([$childB]);
        $childrenOfBQb = $this->createMockQueryBuilder([$grandchildC]);
        $childrenOfCQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([
            $directHiddenQb,
            $childrenOfAQb,
            $childrenOfBQb,
            $childrenOfCQb,
        ]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertArrayHasKey(200, $result, 'Container A must be a candidate');
        self::assertArrayHasKey(201, $result, 'Child B must be a candidate');
        self::assertArrayHasKey(202, $result, 'Grandchild C must be a candidate');
    }

    /**
     * Test 7 (cycleDetection): Records A (UID 300) and B (UID 301) where
     * A.tx_container_parent=B.uid and B.tx_container_parent=A.uid.
     * No infinite loop. Logger receives a warning call. Processing continues.
     */
    public function testCycleDetection(): void
    {
        $oldTstamp = time() - (90 * 86400);

        $recordA = [
            'uid'                 => 300,
            'pid'                 => 1,
            'CType'               => 'b13-container',
            'header'              => 'Record A',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 301,
        ];
        $recordB = [
            'uid'                 => 301,
            'pid'                 => 1,
            'CType'               => 'b13-container',
            'header'              => 'Record B',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 300,
        ];

        // Pass 1: direct hidden -> record A (uid=300)
        // BFS: children of 300 -> B (uid=301)
        // BFS: children of 301 -> A (uid=300) — cycle!
        // 300 is in $seen, so warning logged and continue
        $directHiddenQb = $this->createMockQueryBuilder([$recordA]);
        $childrenOf300Qb = $this->createMockQueryBuilder([$recordB]);
        $childrenOf301Qb = $this->createMockQueryBuilder([$recordA]);

        $connectionPool = $this->createConnectionPoolStub([
            $directHiddenQb,
            $childrenOf300Qb,
            $childrenOf301Qb,
        ]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        /** @var MockObject&LoggerInterface $logger */
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('ycle'),
                self::isArray()
            );

        $subject = new FinderService($connectionPool, $pageExclusionRepo, $logger);
        $result = $subject->findCandidates(30);

        // Processing must complete (no infinite loop) and return collected candidates
        self::assertArrayHasKey(300, $result, 'Record A must be in candidates');
        self::assertArrayHasKey(301, $result, 'Record B must be in candidates');
    }

    /**
     * Test 8 (noContainerParent): A plain hidden tt_content with tx_container_parent=0.
     * Returned normally (no container traversal needed beyond children query returning empty).
     */
    public function testNoContainerParent(): void
    {
        $oldTstamp = time() - (90 * 86400);

        $plainRow = [
            'uid'                 => 400,
            'pid'                 => 5,
            'CType'               => 'text',
            'header'              => 'Plain hidden record',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 0,
        ];

        $directHiddenQb = $this->createMockQueryBuilder([$plainRow]);
        $childrenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb, $childrenQb]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertArrayHasKey(400, $result, 'Plain hidden record with no container parent must be returned');
        self::assertSame(0, $result[400]['tx_container_parent']);
    }

    /**
     * Test 9 (startPidScoping): When startPid=5, only records on pages within that
     * subtree are returned. The subtree is resolved before the direct-hidden query.
     */
    public function testStartPidScoping(): void
    {
        $oldTstamp = time() - (90 * 86400);

        // Record on page 10 (inside subtree rooted at 5)
        $inSubtreeRow = [
            'uid'                 => 500,
            'pid'                 => 10,
            'CType'               => 'text',
            'header'              => 'In subtree',
            'tstamp'              => $oldTstamp,
            'tx_container_parent' => 0,
        ];

        // Subtree resolution for startPid=5:
        //   BFS iteration 1: children of [5] -> [10, 11]
        //   BFS iteration 2: children of [10, 11] -> []
        $subtreeQb1 = $this->createMockQueryBuilderForPages([10, 11]);
        $subtreeQb2 = $this->createMockQueryBuilderForPages([]);

        // Pass 1: direct hidden query (scoped to pages [5, 10, 11]) returns inSubtreeRow
        $directHiddenQb = $this->createMockQueryBuilder([$inSubtreeRow]);
        // Pass 2: children of 500 -> none
        $childrenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([
            $subtreeQb1,
            $subtreeQb2,
            $directHiddenQb,
            $childrenQb,
        ]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(days: 30, startPid: 5);

        self::assertArrayHasKey(500, $result, 'Record in subtree must be returned when startPid scoping is active');
    }

    /**
     * Test 10 (emptyResult): No hidden records exist — returns empty array.
     */
    public function testEmptyResult(): void
    {
        // Pass 1: direct hidden query returns no rows
        $directHiddenQb = $this->createMockQueryBuilder([]);

        $connectionPool = $this->createConnectionPoolStub([$directHiddenQb]);

        $pageExclusionRepo = $this->createStub(PageExclusionRepository::class);
        $pageExclusionRepo->method('getExcludedPageIds')->willReturn([]);

        $subject = new FinderService($connectionPool, $pageExclusionRepo, new NullLogger());
        $result = $subject->findCandidates(30);

        self::assertSame([], $result, 'When no hidden records exist, result must be empty');
    }

    /**
     * Creates a QueryBuilder stub for pages table queries (subtree resolution).
     * Returns UIDs via fetchFirstColumn (unlike tt_content queries that use fetchAllAssociative).
     */
    private function createMockQueryBuilderForPages(array $uids): Stub&QueryBuilder
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('uid IN (...)');

        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($uids);
        $result->method('fetchAllAssociative')->willReturn([]);

        $restrictionContainer = $this->createStub(\TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface::class);
        $restrictionContainer->method('removeAll')->willReturnSelf();
        $restrictionContainer->method('add')->willReturnSelf();

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictionContainer);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value): string {
                if (is_array($value)) {
                    return implode(',', $value);
                }

                return (string)$value;
            }
        );

        return $queryBuilder;
    }
}
