<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Factory;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates fresh DataHandler instances for CleanupService.
 *
 * Extracted as a dedicated injectable class so that CleanupService
 * can be wired by the DI container without requiring a Closure argument.
 */
class DataHandlerFactory
{
    public function create(): DataHandler
    {
        return GeneralUtility::makeInstance(DataHandler::class);
    }
}
