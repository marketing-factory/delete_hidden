<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Service;

use MFD\DeleteHidden\Domain\CleanupResult;
use MFD\DeleteHidden\Factory\DataHandlerFactory;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Deletion engine for hidden tt_content records.
 *
 * Delegates candidate discovery to FinderService and soft-deletion to DataHandler
 * via process_cmdmap(). Supports a per-run limit and dry-run mode.
 *
 * Key invariants:
 *  - No direct SQL is used for deletion — DataHandler is the only permitted path.
 *  - A fresh DataHandler instance is created per record via the factory callable.
 *  - Any DataHandler error aborts the run immediately (partial result returned).
 *  - When candidate count exceeds limit, only the first N candidates are processed.
 */
class CleanupService
{
    /**
     * Default safety cap — runs with more candidates than this are aborted unless overridden.
     */
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly FinderService $finderService,
        private readonly LoggerInterface $logger,
        private readonly DataHandlerFactory $dataHandlerFactory,
    ) {
    }

    /**
     * Runs the cleanup process.
     *
     * @param int $days      Minimum age in days for candidate selection.
     * @param int $startPid  When > 0, scope candidate discovery to this page subtree.
     * @param int $limit     Maximum number of records to process per run. Default is 500.
     *     When more candidates exist than the limit, only the first N are processed.
     * @param bool $dryRun   When true, returns all candidates without performing any deletions.
     *
     * @return CleanupResult
     */
    /**
     * @param callable(int $done, int $total): void|null $onProgress Called after each successful deletion.
     */
    public function run(int $days, int $startPid = 0, int $limit = self::DEFAULT_LIMIT, bool $dryRun = false, ?callable $onProgress = null): CleanupResult
    {
        $candidates = $this->finderService->findCandidates($days, $startPid);
        $candidateCount = count($candidates);

        // Dry-run: return all candidates without any DataHandler calls
        if ($dryRun) {
            return new CleanupResult(
                candidateCount: $candidateCount,
                deletedCount: 0,
                errors: [],
                limitApplied: false,
                candidates: $candidates,
            );
        }

        // Trim candidates to the requested limit
        $limitApplied = $candidateCount > $limit;

        if ($limitApplied) {
            $candidates = array_slice($candidates, 0, $limit, true);
        }

        // Deletion loop: one fresh DataHandler per record
        $deletedCount = 0;
        $errors = [];

        foreach ($candidates as $uid => $record) {
            /** @var DataHandler $dataHandler */
            $dataHandler = $this->dataHandlerFactory->create();

            $cmd = ['tt_content' => [(int)$uid => ['delete' => 1]]];
            $dataHandler->start([], $cmd);
            $dataHandler->process_cmdmap();

            if (!empty($dataHandler->errorLog)) {
                $this->logger->error(
                    'DataHandler error during cleanup — aborting run',
                    ['uid' => $uid, 'errors' => $dataHandler->errorLog]
                );
                $errors = $dataHandler->errorLog;
                break;
            }

            $deletedCount++;

            if ($onProgress !== null) {
                $onProgress($deletedCount, count($candidates));
            }
        }

        return new CleanupResult(
            candidateCount: $candidateCount,
            deletedCount: $deletedCount,
            errors: $errors,
            limitApplied: $limitApplied,
            candidates: $candidates,
        );
    }
}
