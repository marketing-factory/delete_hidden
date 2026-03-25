<?php

defined('TYPO3') or die();

use MFD\DeleteHidden\Task\CleanupTask;
use MFD\DeleteHidden\Task\CleanupTaskAdditionalFieldProvider;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][CleanupTask::class] = [
    'extension' => 'delete_hidden',
    'title' => 'LLL:EXT:delete_hidden/Resources/Private/Language/locallang_tasks.xlf:task.cleanupTask.title',
    'description' => 'LLL:EXT:delete_hidden/Resources/Private/Language/locallang_tasks.xlf:task.cleanupTask.description',
    'additionalFields' => CleanupTaskAdditionalFieldProvider::class,
];
