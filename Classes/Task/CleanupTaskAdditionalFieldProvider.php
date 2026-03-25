<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Task;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\SchedulerManagementAction;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Provides the days and limit form fields for CleanupTask in the Scheduler backend module.
 *
 * Renders two integer input fields, validates them on submit, and writes their values
 * back to the task's public properties via saveAdditionalFields().
 */
class CleanupTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        $currentAction = $schedulerModule->getCurrentAction();

        if (!isset($taskInfo['tx_delete_hidden_days'])) {
            $taskInfo['tx_delete_hidden_days'] = 90;
            if ($currentAction === SchedulerManagementAction::EDIT && $task instanceof CleanupTask) {
                $taskInfo['tx_delete_hidden_days'] = $task->days;
            }
        }

        if (!isset($taskInfo['tx_delete_hidden_limit'])) {
            $taskInfo['tx_delete_hidden_limit'] = 500;
            if ($currentAction === SchedulerManagementAction::EDIT && $task instanceof CleanupTask) {
                $taskInfo['tx_delete_hidden_limit'] = $task->limit;
            }
        }

        return [
            'task_delete_hidden_days' => [
                'code' => '<input class="form-control" type="text" name="tx_scheduler[tx_delete_hidden_days]"'
                    . ' id="task_delete_hidden_days" value="' . htmlspecialchars((string)(int)$taskInfo['tx_delete_hidden_days']) . '">',
                'label' => 'Minimum age in days (must be >= 1)',
                'type' => 'input',
            ],
            'task_delete_hidden_limit' => [
                'code' => '<input class="form-control" type="text" name="tx_scheduler[tx_delete_hidden_limit]"'
                    . ' id="task_delete_hidden_limit" value="' . htmlspecialchars((string)(int)$taskInfo['tx_delete_hidden_limit']) . '">',
                'label' => 'Safety cap (maximum records per run, must be >= 1)',
                'type' => 'input',
            ],
        ];
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $valid = true;

        $days = (int)($submittedData['tx_delete_hidden_days'] ?? 0);
        if (!is_numeric($submittedData['tx_delete_hidden_days'] ?? '') || $days < 1) {
            $this->addMessage('Days must be an integer >= 1.', ContextualFeedbackSeverity::ERROR);
            $valid = false;
        }

        $limit = (int)($submittedData['tx_delete_hidden_limit'] ?? 0);
        if (!is_numeric($submittedData['tx_delete_hidden_limit'] ?? '') || $limit < 1) {
            $this->addMessage('Limit must be an integer >= 1.', ContextualFeedbackSeverity::ERROR);
            $valid = false;
        }

        return $valid;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        if (!($task instanceof CleanupTask)) {
            return;
        }

        $task->days = (int)$submittedData['tx_delete_hidden_days'];
        $task->limit = (int)$submittedData['tx_delete_hidden_limit'];
    }
}
