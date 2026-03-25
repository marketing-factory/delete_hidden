<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Task;

use MFD\DeleteHidden\Service\CleanupService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task that delegates to CleanupService to soft-delete hidden tt_content records.
 *
 * Public non-readonly properties (days, limit) survive PHP serialize()/unserialize() —
 * required because the Scheduler persists tasks as serialized objects in the database.
 *
 * Services are instantiated via GeneralUtility::makeInstance() inside execute() rather
 * than constructor DI, since constructor arguments are not restored after unserialize().
 */
class CleanupTask extends AbstractTask
{
    public int $days = 90;
    public int $limit = 500;

    public function execute(): bool
    {
        /** @var CleanupService $cleanupService */
        $cleanupService = GeneralUtility::makeInstance(CleanupService::class);
        $result = $cleanupService->run(
            days: $this->days,
            startPid: 0,
            limit: $this->limit,
            dryRun: false,
        );

        if (!empty($result->errors)) {
            throw new \RuntimeException(
                sprintf(
                    'DataHandler error during cleanup run: %s',
                    implode('; ', $result->errors),
                ),
            );
        }

        return true;
    }

    public function getAdditionalInformation(): string
    {
        return sprintf('days: %d, limit: %d', $this->days, $this->limit);
    }
}
