<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Resolves which page IDs are excluded from deletion processing.
 *
 * A page is excluded when it carries the tx_delete_hidden_exclude = 1 flag.
 * Tree inheritance applies: all descendant pages of a flagged page are also
 * considered excluded, even if they themselves are not flagged.
 *
 * When $startPid > 0, only pages within that subtree are considered.
 */
class PageExclusionRepository
{
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * Returns a flat array of page UIDs that are excluded from deletion processing.
     *
     * Includes the directly flagged pages and all their descendants (tree-inherited exclusion).
     *
     * @param int $startPid When > 0, only pages within this subtree are considered.
     * @return array<int> Flat list of excluded page UIDs.
     */
    public function getExcludedPageIds(int $startPid = 0): array
    {
        // Step 1: When startPid > 0, resolve the full subtree to scope the search.
        $subtreeIds = null;
        if ($startPid > 0) {
            $subtreeIds = $this->resolveSubtreeIds($startPid);
            // Include the start page itself
            $subtreeIds[] = $startPid;
        }

        // Step 2: Fetch all directly flagged page UIDs.
        $flaggedPids = $this->fetchFlaggedPageIds($subtreeIds);

        if (empty($flaggedPids)) {
            return [];
        }

        // Step 3: BFS to collect all descendants of the flagged pages.
        $descendantPids = $this->resolveDescendants($flaggedPids, $subtreeIds);

        // Step 4: Merge flagged pages + descendants into a unique flat array.
        return array_values(array_unique(array_merge($flaggedPids, $descendantPids)));
    }

    /**
     * Fetches page UIDs where tx_delete_hidden_exclude = 1.
     * Optionally scoped to a set of allowed UIDs (subtree).
     *
     * @param array<int>|null $scopeUids When set, only pages whose UIDs are in this list are returned.
     * @return array<int>
     */
    private function fetchFlaggedPageIds(?array $scopeUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $constraints = [
            $queryBuilder->expr()->eq(
                'tx_delete_hidden_exclude',
                $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
            ),
        ];

        if ($scopeUids !== null && !empty($scopeUids)) {
            $constraints[] = $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($scopeUids, Connection::PARAM_INT_ARRAY)
            );
        }

        $result = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(...$constraints)
            ->executeQuery();

        return array_map('intval', $result->fetchFirstColumn());
    }

    /**
     * Resolves all descendant page UIDs of the given parent UIDs using iterative BFS.
     * Optionally restricts results to pages within a given scope set.
     *
     * @param array<int> $rootUids Starting parent UIDs.
     * @param array<int>|null $scopeUids When set, only descendants whose UIDs are in this list are included.
     * @return array<int>
     */
    private function resolveDescendants(array $rootUids, ?array $scopeUids): array
    {
        $allDescendants = [];
        $queue = $rootUids;

        while (!empty($queue)) {
            $childUids = $this->fetchChildPageIds($queue);

            if (empty($childUids)) {
                break;
            }

            if ($scopeUids !== null) {
                $childUids = array_values(array_intersect($childUids, $scopeUids));
            }

            // Only enqueue pages we haven't visited to prevent infinite loops.
            $newChildren = array_diff($childUids, $allDescendants, $rootUids);
            $allDescendants = array_merge($allDescendants, $newChildren);
            $queue = $newChildren;
        }

        return $allDescendants;
    }

    /**
     * Fetches child page UIDs for the given parent UIDs (one level).
     *
     * @param array<int> $parentUids
     * @return array<int>
     */
    private function fetchChildPageIds(array $parentUids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $result = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery();

        return array_map('intval', $result->fetchFirstColumn());
    }

    /**
     * Resolves all page UIDs within the subtree rooted at $startPid (excluding $startPid itself).
     * Uses iterative BFS.
     *
     * @return array<int>
     */
    private function resolveSubtreeIds(int $startPid): array
    {
        $allIds = [];
        $queue = [$startPid];

        while (!empty($queue)) {
            $childUids = $this->fetchChildPageIds($queue);

            if (empty($childUids)) {
                break;
            }

            $newIds = array_diff($childUids, $allIds);
            $allIds = array_merge($allIds, $newIds);
            $queue = $newIds;
        }

        return $allIds;
    }
}
