<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Domain;

/**
 * Typed result value object returned by CleanupService::run().
 *
 * Carries the outcome of a cleanup run including candidate count, deletion count,
 * safety cap status, any DataHandler errors, and the raw candidates map for
 * table rendering in CleanupCommand (e.g. dry-run output).
 */
readonly class CleanupResult
{
    /**
     * @param int $candidateCount  Total number of candidates found by FinderService.
     * @param int $deletedCount    Number of records successfully soft-deleted via DataHandler.
     * @param list<string> $errors DataHandler errorLog entries from the first failed record.
     * @param bool $limitApplied   True when the candidate list was trimmed to the --limit value.
     * @param array<int, array{pid: int, ctype: string, title: string, tstamp: int, tx_container_parent: int}> $candidates
     *     Raw candidates map from FinderService (populated in dry-run mode).
     */
    public function __construct(
        public int $candidateCount,
        public int $deletedCount,
        public array $errors,
        public bool $limitApplied,
        public array $candidates,
    ) {
    }
}
