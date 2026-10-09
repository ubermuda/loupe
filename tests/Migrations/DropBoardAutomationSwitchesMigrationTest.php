<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261009203200;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261009203200.php';

final class DropBoardAutomationSwitchesMigrationTest extends KernelTestCase
{
    private const array DROPPED = [
        'merge_strategy', 'fix_strategy', 'loop_limit', 'epic_branch_pattern',
        'comment_on_fix_queued', 'comment_on_stale_approval', 'sync_behind', 'merge_pull_requests', 'change_base',
        'epic_draft_switch', 'close_epic_pull_requests', 'open_epic_pull_requests', 'post_widget_reviews', 'site_review_check',
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_the_switch_columns_come_back_on_the_way_down_and_go_on_the_way_up(): void
    {
        self::assertSame([], array_intersect(self::DROPPED, $this->columns()));

        $this->migrate('down');
        self::assertEqualsCanonicalizing(self::DROPPED, array_intersect(self::DROPPED, $this->columns()));

        $this->migrate('up');
        $left = $this->columns();
        self::assertSame([], array_intersect(self::DROPPED, $left));
        self::assertContains('enabled', $left);
        self::assertContains('terminal_window_days', $left);
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261009203200($this->connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<string> */
    private function columns(): array
    {
        return $this->connection->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'board_automation_settings'");
    }
}
