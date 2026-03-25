<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Unit\Repository;

use Doctrine\DBAL\Result;
use MFD\DeleteHidden\Repository\PageExclusionRepository;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for PageExclusionRepository.
 *
 * The repository resolves page IDs that are excluded from deletion processing,
 * including tree-inherited exclusion (descendants of flagged pages are also excluded).
 */
final class PageExclusionRepositoryTest extends UnitTestCase
{
    /**
     * Creates a stub QueryBuilder whose ->select()->from()->where()->executeQuery()->fetchFirstColumn()
     * chain returns the given array of UIDs.
     *
     * Because QueryBuilder is stateful, we create one stub per query call.
     */
    private function buildQueryBuilderStub(array $returnUids): Stub&QueryBuilder
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('uid IN (...)');

        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($returnUids);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
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
     *
     * When exactly one QueryBuilder is provided it is returned for every call.
     * When multiple are provided they are returned on consecutive calls.
     */
    private function buildConnectionPoolStub(array $queryBuilders): Stub&ConnectionPool
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
     * Test 1: A page with tx_delete_hidden_exclude = 1 is returned in the excluded set.
     */
    public function testDirectExclusion(): void
    {
        // First query: returns UIDs of flagged pages [10]
        $flaggedQb = $this->buildQueryBuilderStub([10]);
        // Second query: children of page 10 -> none
        $childQb1 = $this->buildQueryBuilderStub([]);

        $connectionPool = $this->buildConnectionPoolStub([$flaggedQb, $childQb1]);

        $repository = new PageExclusionRepository($connectionPool);
        $result = $repository->getExcludedPageIds();

        self::assertContains(10, $result, 'Flagged page UID must be in the excluded set');
    }

    /**
     * Test 2: Child and grandchild of a flagged page are also excluded (tree inheritance).
     */
    public function testTreeInheritance(): void
    {
        // First query: flagged pages -> [10]
        $flaggedQb = $this->buildQueryBuilderStub([10]);
        // BFS iteration 1: children of [10] -> [20]
        $childQb1 = $this->buildQueryBuilderStub([20]);
        // BFS iteration 2: children of [20] -> [30]
        $childQb2 = $this->buildQueryBuilderStub([30]);
        // BFS iteration 3: children of [30] -> []
        $childQb3 = $this->buildQueryBuilderStub([]);

        $connectionPool = $this->buildConnectionPoolStub([$flaggedQb, $childQb1, $childQb2, $childQb3]);

        $repository = new PageExclusionRepository($connectionPool);
        $result = $repository->getExcludedPageIds();

        self::assertContains(10, $result, 'Flagged page must be excluded');
        self::assertContains(20, $result, 'Child of flagged page must be excluded');
        self::assertContains(30, $result, 'Grandchild of flagged page must be excluded');
    }

    /**
     * Test 3: When no pages are flagged, returns empty array.
     */
    public function testNoExclusion(): void
    {
        // First (and only) query: no flagged pages
        $flaggedQb = $this->buildQueryBuilderStub([]);

        $connectionPool = $this->buildConnectionPoolStub([$flaggedQb]);

        $repository = new PageExclusionRepository($connectionPool);
        $result = $repository->getExcludedPageIds();

        self::assertSame([], $result, 'When no pages are flagged, result must be empty');
    }

    /**
     * Test 4: When startPid = 5, only pages in that subtree are considered.
     * A flagged page outside the subtree (UID 99) must NOT appear in the result.
     */
    public function testStartPidScoping(): void
    {
        // Subtree resolution for startPid=5:
        //   iteration 1: children of [5] -> [10, 11]
        //   iteration 2: children of [10, 11] -> []
        $subtreeQb1 = $this->buildQueryBuilderStub([10, 11]);
        $subtreeQb2 = $this->buildQueryBuilderStub([]);

        // Flagged pages query (scoped to subtree [5, 10, 11]): page 10 is flagged inside subtree.
        // Page 99 is flagged but NOT in subtree, so implementation must filter it out.
        $flaggedQb = $this->buildQueryBuilderStub([10]);

        // BFS for flagged pages: children of [10] -> []
        $childQb1 = $this->buildQueryBuilderStub([]);

        $connectionPool = $this->buildConnectionPoolStub([$subtreeQb1, $subtreeQb2, $flaggedQb, $childQb1]);

        $repository = new PageExclusionRepository($connectionPool);
        $result = $repository->getExcludedPageIds(startPid: 5);

        self::assertContains(10, $result, 'Page 10 is flagged inside subtree and must be excluded');
        self::assertNotContains(99, $result, 'Page 99 is outside startPid subtree and must NOT be in result');
    }

    /**
     * Test 5: Two independently flagged pages each produce their own descendant sets,
     * all merged into one flat array.
     */
    public function testMultipleFlaggedPages(): void
    {
        // Flagged pages: [10, 20]
        $flaggedQb = $this->buildQueryBuilderStub([10, 20]);
        // BFS iteration 1: children of [10, 20] -> [11, 21]
        $childQb1 = $this->buildQueryBuilderStub([11, 21]);
        // BFS iteration 2: children of [11, 21] -> []
        $childQb2 = $this->buildQueryBuilderStub([]);

        $connectionPool = $this->buildConnectionPoolStub([$flaggedQb, $childQb1, $childQb2]);

        $repository = new PageExclusionRepository($connectionPool);
        $result = $repository->getExcludedPageIds();

        self::assertContains(10, $result);
        self::assertContains(20, $result);
        self::assertContains(11, $result);
        self::assertContains(21, $result);
    }
}
