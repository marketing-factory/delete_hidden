<?php

declare(strict_types=1);

namespace MFD\DeleteHidden\Tests\Functional\Command;

use MFD\DeleteHidden\Command\CleanupCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Doctrine\DBAL\ParameterType;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for CleanupCommand.
 *
 * Each test boots a real TYPO3 instance with the delete_hidden extension loaded,
 * seeds the database with CSV fixtures, and invokes the CLI command via CommandTester.
 *
 * Covered scenarios:
 *  1. Happy path: hidden old record is soft-deleted after command execution
 *  2. Dry-run: no records are deleted, candidates are listed
 *  3. Page exclusion: record on an excluded page is not deleted
 *  4. Safety cap: when candidates exceed limit, only limit records are deleted
 */
final class CleanupCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'b13/container',
        'marketing-factory/delete-hidden',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/base.csv');
    }

    /**
     * Fetches a single tt_content row by uid, bypassing all TYPO3 restrictions
     * (including DeletedRestriction) so soft-deleted records are visible.
     *
     * @return array<string, mixed>|false
     */
    private function fetchTtContentRow(int $uid): array|false
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * Happy path: a hidden record older than --days threshold is soft-deleted.
     */
    public function testHappyPathDeletion(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_hidden_old.csv');
        $this->setUpBackendUser(1);

        /** @var CleanupCommand $command */
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $row = $this->fetchTtContentRow(1);

        self::assertIsArray($row);
        self::assertSame(1, (int)$row['deleted']);
    }

    /**
     * Dry-run: no records are soft-deleted and the output lists the candidate count.
     */
    public function testDryRunDoesNotDelete(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_hidden_old.csv');
        $this->setUpBackendUser(1);

        /** @var CleanupCommand $command */
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '1', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $row = $this->fetchTtContentRow(1);

        self::assertIsArray($row);
        self::assertSame(0, (int)$row['deleted']);
        self::assertStringContainsString('Found 1 candidates', $tester->getDisplay());
    }

    /**
     * Page exclusion: record on a page with tx_delete_hidden_exclude=1 is not deleted.
     */
    public function testPageExclusionPreventsDelete(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_excluded_page.csv');
        $this->setUpBackendUser(1);

        /** @var CleanupCommand $command */
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $row = $this->fetchTtContentRow(10);

        self::assertIsArray($row);
        self::assertSame(0, (int)$row['deleted']);
    }

    /**
     * Safety cap: when candidates exceed limit, only limit records are deleted.
     * Exit code is SUCCESS (not FAILURE) — limitApplied returns SUCCESS with a note.
     */
    public function testSafetyCapLimitsDeletedRecords(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_safety_cap.csv');
        $this->setUpBackendUser(1);

        /** @var CleanupCommand $command */
        $command = $this->get(CleanupCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '1', '--limit' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $deletedCount = 0;
        $survivingCount = 0;

        foreach ([20, 21, 22] as $uid) {
            $row = $this->fetchTtContentRow($uid);
            self::assertIsArray($row);
            if ((int)$row['deleted'] === 1) {
                $deletedCount++;
            } else {
                $survivingCount++;
            }
        }

        self::assertSame(2, $deletedCount, 'Exactly 2 records should have been deleted');
        self::assertSame(1, $survivingCount, 'Exactly 1 record should have survived');
        self::assertStringContainsString('more candidates remain', $tester->getDisplay());
    }
}
