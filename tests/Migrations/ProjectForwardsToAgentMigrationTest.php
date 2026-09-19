<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260919164809;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260919164809.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class ProjectForwardsToAgentMigrationTest extends KernelTestCase
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

    public function test_up_copies_each_widget_token_flag_onto_its_project(): void
    {
        $forwarding = $this->projectWithWidgetToken('forwarding');
        $collectOnly = $this->projectWithWidgetToken('collect-only');
        $noToken = $this->project('no-token');
        $mcpOnly = $this->projectWithMcpToken('mcp-only');
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        $this->setTokenFlag($forwarding, true);
        $this->setTokenFlag($collectOnly, false);
        $this->setTokenFlag($mcpOnly, true);
        // Guard: the column is gone, so the values below can only come from up().
        self::assertFalse($this->projectsHaveTheColumn());

        $this->migrate();

        self::assertTrue($this->projectFlag($forwarding));
        self::assertFalse($this->projectFlag($collectOnly));
        self::assertFalse($this->projectFlag($noToken));
        self::assertFalse($this->projectFlag($mcpOnly), 'only the widget token carried the flag');
    }

    public function test_up_leaves_the_token_column_for_the_previous_image(): void
    {
        $this->migrate(down: true);
        $this->migrate();

        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM information_schema.columns WHERE table_name = 'api_tokens' AND column_name = 'forwards_to_agent'",
        ));
    }

    public function test_down_copies_the_project_flag_back_onto_its_widget_token(): void
    {
        $project = $this->projectWithWidgetToken('down');
        $project->forwardsToAgent = true;
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);

        self::assertFalse($this->projectsHaveTheColumn());
        self::assertTrue((bool) $this->connection->fetchOne(
            'SELECT t.forwards_to_agent FROM api_tokens t JOIN projects p ON p.widget_token_id = t.id WHERE p.id = :id',
            ['id' => (string) $project->id],
        ));
    }

    private function project(string $name): Project
    {
        $owner = new User(fullName: 'Riley', email: 'forwards-migration-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $name);
        $this->em->persist($project);

        return $project;
    }

    private function projectWithWidgetToken(string $name): Project
    {
        $project = $this->project($name);
        [$token] = ApiToken::issue($project->owner, 'Widget', ApiTokenScope::SiteReview);
        $this->em->persist($token);
        $project->widgetToken = $token;

        return $project;
    }

    private function projectWithMcpToken(string $name): Project
    {
        $project = $this->project($name);
        [$token] = ApiToken::issue($project->owner, 'MCP', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $project->mcpToken = $token;

        return $project;
    }

    private function setTokenFlag(Project $project, bool $value): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE api_tokens SET forwards_to_agent = :value
             WHERE id IN (SELECT widget_token_id FROM projects WHERE id = :id UNION SELECT mcp_token_id FROM projects WHERE id = :id)',
            ['value' => $value, 'id' => (string) $project->id],
            ['value' => 'boolean'],
        );
        self::assertSame(1, $affected);
    }

    private function projectFlag(Project $project): bool
    {
        return (bool) $this->connection->fetchOne('SELECT forwards_to_agent FROM projects WHERE id = :id', ['id' => (string) $project->id]);
    }

    private function projectsHaveTheColumn(): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            "SELECT count(*) FROM information_schema.columns WHERE table_name = 'projects' AND column_name = 'forwards_to_agent'",
        );
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20260919164809($this->connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
