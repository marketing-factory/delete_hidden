<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Command;

use MFD\DeleteHidden\Domain\CleanupResult;
use MFD\DeleteHidden\Service\CleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CLI command that exposes CleanupService as `typo3 delete_hidden:cleanup`.
 *
 * Supports --days, --dry-run, --page, and --limit options. Handles input
 * validation, table output for dry-run and verbose modes, safety cap
 * enforcement, and delegates all business logic to CleanupService.
 */
#[AsCommand(
    name: 'delete_hidden:cleanup',
    description: 'Soft-deletes hidden tt_content records older than N days.',
)]
final class CleanupCommand extends Command
{
    public function __construct(
        private readonly CleanupService $cleanupService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Minimum age in days', 90)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List candidates without deleting')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Scope to page subtree (UID)', 0)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Safety cap override', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();

        $io = new SymfonyStyle($input, $output);

        $days = (int)$input->getOption('days');
        $limit = (int)$input->getOption('limit');
        $page = (int)$input->getOption('page');
        $dryRun = (bool)$input->getOption('dry-run');

        if ($days < 1) {
            $io->error(sprintf('--days must be at least 1. Got: %d.', $days));
            return Command::FAILURE;
        }

        $progressBar = null;

        if (!$dryRun) {
            $progressBar = new ProgressBar($output);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        }

        $result = $this->cleanupService->run(
            $days,
            $page,
            $limit,
            $dryRun,
            $progressBar !== null
                ? static function (int $done, int $total) use ($progressBar): void {
                    if ($done === 1) {
                        $progressBar->start($total);
                    }

                    $progressBar->setProgress($done);
                }
                : null,
        );

        if ($progressBar !== null && $result->deletedCount > 0) {
            $progressBar->finish();
            $output->writeln('');
        }

        if ($dryRun) {
            if ($result->candidateCount === 0) {
                $output->writeln(sprintf('No hidden content elements older than %d days found.', $days));
            } else {
                $this->renderCandidatesTable($output, $result);
                $this->renderSummaryTable($output, $result);
                $output->writeln(sprintf(
                    'Found %d candidates older than %d days. Run without --dry-run to delete.',
                    $result->candidateCount,
                    $days
                ));
            }

            return Command::SUCCESS;
        }

        if (!empty($result->errors)) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }

            return Command::FAILURE;
        }

        if ($output->isVerbose()) {
            $this->renderCandidatesTable($output, $result);
        }

        $output->writeln(sprintf(
            'Deleted %d hidden content elements older than %d days.',
            $result->deletedCount,
            $days
        ));

        if ($result->limitApplied) {
            $io->note(sprintf(
                '%d more candidates remain. Run again to continue.',
                $result->candidateCount - $result->deletedCount
            ));
        }

        return Command::SUCCESS;
    }

    private function renderCandidatesTable(OutputInterface $output, CleanupResult $result): void
    {
        $table = new Table($output);
        $table->setHeaders(['UID', 'PID', 'CType', 'Title', 'Age (days)', 'Container Parent UID']);
        $table->setColumnMaxWidth(3, 40);

        $candidates = $result->candidates;
        uasort($candidates, static fn(array $a, array $b): int => $a['pid'] <=> $b['pid']);

        foreach ($candidates as $uid => $record) {
            $age = (int)round((time() - $record['tstamp']) / 86400);
            $containerParent = $record['tx_container_parent'] ?: '-';
            $table->addRow([$uid, $record['pid'], $record['ctype'], $record['title'], $age, $containerParent]);
        }

        $table->render();
    }

    private function renderSummaryTable(OutputInterface $output, CleanupResult $result): void
    {
        $counts = [];

        foreach ($result->candidates as $record) {
            $pid = $record['pid'];
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }

        arsort($counts);

        $pageTitles = $this->fetchPageTitles(array_keys($counts));

        $table = new Table($output);
        $table->setHeaders(['PID', 'Page', 'Records']);

        foreach ($counts as $pid => $count) {
            $table->addRow([$pid, $pageTitles[$pid] ?? '(unknown)', $count]);
        }

        $table->render();
    }

    /**
     * Fetches page titles for the given PIDs in a single query.
     *
     * @param array<int> $pids
     * @return array<int, string> Map of PID → title
     */
    private function fetchPageTitles(array $pids): array
    {
        if (empty($pids)) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];

        foreach ($rows as $row) {
            $titles[(int)$row['uid']] = $row['title'];
        }

        return $titles;
    }
}
