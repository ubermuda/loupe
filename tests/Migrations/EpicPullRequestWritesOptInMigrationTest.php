<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261003045733;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261003045733.php';

final class EpicPullRequestWritesOptInMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_a_project_with_its_automation_on_turns_on_both_epic_writes(): void
    {
        $project = $this->project();
        $this->em->persist(new BoardAutomationSettings($project, enabled: true, syncBehind: true));
        $this->em->flush();

        $this->migrate();

        self::assertSame([['enabled' => true, 'sync_behind' => true, 'epic_draft_switch' => true, 'close_epic_pull_requests' => true]], $this->settings($project));
    }

    public function test_a_project_with_its_automation_off_keeps_both_epic_writes_off(): void
    {
        $project = $this->project();
        $this->em->persist(new BoardAutomationSettings($project, enabled: false));
        $this->em->flush();

        $this->migrate();

        self::assertSame([['enabled' => false, 'sync_behind' => false, 'epic_draft_switch' => false, 'close_epic_pull_requests' => false]], $this->settings($project));
    }

    public function test_a_project_with_no_settings_gets_the_defaults_with_both_epic_writes_on(): void
    {
        $project = $this->project();

        $this->migrate();

        self::assertSame([['enabled' => true, 'sync_behind' => false, 'epic_draft_switch' => true, 'close_epic_pull_requests' => true]], $this->settings($project));
        self::assertSame(
            ['merge_strategy' => 'worker', 'fix_strategy' => 'fresh', 'loop_limit' => 3, 'terminal_window_days' => 3],
            $this->connection->fetchAssociative('SELECT merge_strategy, fix_strategy, loop_limit, terminal_window_days FROM board_automation_settings WHERE project_id = ?', [(string) $project->id]),
        );
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'opt-in-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'opt-in-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function migrate(): void
    {
        $migration = new Version20261003045733($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function settings(Project $project): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT enabled, sync_behind, epic_draft_switch, close_epic_pull_requests FROM board_automation_settings WHERE project_id = ?',
            [(string) $project->id],
        );
    }
}
