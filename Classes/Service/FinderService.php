<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Service;

use MFD\DeleteHidden\Repository\PageExclusionRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds tt_content records that are candidates for deletion.
 *
 * A record is a candidate when:
 *  1. It is directly hidden (hidden = 1) and its tstamp is older than $days days.
 *  2. It is a child (via tx_container_parent chain) of such a directly-hidden record,
 *     regardless of its own hidden flag or tstamp.
 *
 * Workspace records (t3ver_wsid > 0) are never returned.
 * Content on excluded pages (from PageExclusionRepository) is filtered out.
 * Circular tx_container_parent references are detected, logged, and skipped.
 */
class FinderService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PageExclusionRepository $pageExclusionRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns all tt_content records that are candidates for deletion.
     *
     * @param int $days  Minimum age in days (records older than this are considered).
     * @param int $startPid  When > 0, scope the search to this page subtree only.
     *
     * @return array<int, array{pid: int, ctype: string, title: string, tstamp: int, tx_container_parent: int}>
     */
    public function findCandidates(int $days, int $startPid = 0): array
    {
        $cutoff = time() - ($days * 86400);

        // Resolve subtree page IDs when startPid scoping is requested
        $subtreePageIds = [];
        if ($startPid > 0) {
            $subtreePageIds = $this->resolveSubtreePageIds($startPid);
            $subtreePageIds[] = $startPid;
        }

        // Pass 1: Fetch directly hidden records older than $days days
        $directHidden = $this->fetchDirectlyHidden($cutoff, $subtreePageIds);

        if (empty($directHidden)) {
            return [];
        }

        // Index directly hidden candidates by UID
        $candidates = [];
        foreach ($directHidden as $row) {
            $uid = (int)$row['uid'];
            $candidates[$uid] = $this->normalizeRow($row);
        }

        // Pass 2: BFS traversal — find all descendants of the directly hidden records
        $directUids = array_keys($candidates);
        $descendants = $this->resolveDescendants($directUids);
        foreach ($descendants as $uid => $record) {
            $candidates[$uid] = $record;
        }

        // Filter — Remove candidates on excluded pages
        $excludedPids = $this->pageExclusionRepository->getExcludedPageIds($startPid);
        if (!empty($excludedPids)) {
            foreach ($candidates as $uid => $record) {
                if (in_array($record['pid'], $excludedPids, true)) {
                    unset($candidates[$uid]);
                }
            }
        }

        return $candidates;
    }

    /**
     * Fetches tt_content records that are directly hidden and older than the cutoff.
     *
     * TYPO3 default restrictions are cleared (so hidden records are visible), and
     * only DeletedRestriction is kept to exclude soft-deleted records.
     *
     * @param int $cutoff  Unix timestamp — records with tstamp < $cutoff qualify.
     * @param array<int> $pageIds  When non-empty, limit to these page UIDs.
     * @return list<array<string, mixed>>
     */
    private function fetchDirectlyHidden(int $cutoff, array $pageIds = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'tstamp', 'tx_container_parent')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'hidden',
                    $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->lt(
                    'tstamp',
                    $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT)
                )
            );

        if (!empty($pageIds)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Fetches all direct children of a given tt_content record via tx_container_parent.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchChildrenOf(int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'tstamp', 'tx_container_parent')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_container_parent',
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * BFS traversal over tx_container_parent links starting from the given parent UIDs.
     *
     * Uses a global $seen set to detect and skip circular references. When a cycle
     * is encountered, a warning is logged and the UID is skipped.
     *
     * Children are included regardless of their own hidden flag or tstamp.
     *
     * @param array<int> $parentUids  UIDs of directly-hidden records to traverse from.
     * @return array<int, array{pid: int, ctype: string, title: string, tstamp: int, tx_container_parent: int}>
     */
    private function resolveDescendants(array $parentUids): array
    {
        $descendants = [];
        // $seen tracks every UID we have already processed to prevent infinite loops
        $seen = array_fill_keys($parentUids, true);
        $queue = $parentUids;

        while (!empty($queue)) {
            $uid = array_shift($queue);

            $children = $this->fetchChildrenOf($uid);
            foreach ($children as $child) {
                $childUid = (int)$child['uid'];

                if (isset($seen[$childUid])) {
                    $this->logger->warning(
                        'Cycle detected in tx_container_parent chain',
                        ['uid' => $childUid]
                    );
                    continue;
                }

                $seen[$childUid] = true;
                $descendants[$childUid] = $this->normalizeRow($child);
                $queue[] = $childUid;
            }
        }

        return $descendants;
    }

    /**
     * Resolves all page UIDs within the subtree rooted at $startPid using iterative BFS.
     * Does NOT include $startPid itself (the caller adds it).
     *
     * @return array<int>
     */
    private function resolveSubtreePageIds(int $startPid): array
    {
        $allIds = [];
        $queue = [$startPid];

        while (!empty($queue)) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

            $childUids = array_map(
                'intval',
                $queryBuilder
                    ->select('uid')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->in(
                            'pid',
                            $queryBuilder->createNamedParameter($queue, Connection::PARAM_INT_ARRAY)
                        )
                    )
                    ->executeQuery()
                    ->fetchFirstColumn()
            );

            if (empty($childUids)) {
                break;
            }

            $newIds = array_diff($childUids, $allIds, [$startPid]);
            $allIds = array_merge($allIds, $newIds);
            $queue = $newIds;
        }

        return $allIds;
    }

    /**
     * Maps a raw DB row (with TYPO3 column names) to the canonical candidate shape.
     *
     * @param array<string, mixed> $row
     * @return array{pid: int, ctype: string, title: string, tstamp: int, tx_container_parent: int}
     */
    private function normalizeRow(array $row): array
    {
        return [
            'pid'                 => (int)$row['pid'],
            'ctype'               => (string)$row['CType'],
            'title'               => (string)$row['header'],
            'tstamp'              => (int)$row['tstamp'],
            'tx_container_parent' => (int)$row['tx_container_parent'],
        ];
    }
}
