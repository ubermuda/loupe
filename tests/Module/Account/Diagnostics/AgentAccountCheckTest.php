<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Diagnostics;

use App\Module\Account\Diagnostics\AgentAccountCheck;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * Runs against the real test database, because the whole point of the check is
 * whether the row the migrations insert is actually there.
 */
final class AgentAccountCheckTest extends KernelTestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function test_the_agent_account_is_reported_present_when_the_row_exists(): void
    {
        $diagnostic = new AgentAccountCheck($this->connection, new NullLogger())();

        self::assertSame('agent_account', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
    }

    public function test_a_missing_agent_account_is_a_failure_not_a_warning(): void
    {
        // Deleting it is safe here: dama/doctrine-test-bundle rolls the whole
        // test back, and nothing else in this test reads the row.
        $this->connection->delete('users', ['id' => User::AGENT_ID]);

        $diagnostic = new AgentAccountCheck($this->connection, new NullLogger())();

        // Failed rather than Warning: every comment written through the MCP is
        // authored by this row, so without it that path does not degrade, it
        // breaks.
        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
    }
}
