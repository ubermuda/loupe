<?php

declare(strict_types=1);

namespace App\Module\Account\Diagnostics;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * Every comment an agent writes over MCP is authored by one singleton user row,
 * so without it the first agent reply fails inside a write — far from the
 * cause, and only for the operator who happens to be using the MCP. Cheap to
 * check and impossible to infer from anything else on the page.
 */
final readonly class AgentAccountCheck implements DiagnosticInterface
{
    private const string AGENT_ACCOUNT_SQL = 'SELECT COUNT(*) FROM users WHERE id = :id'; // @translation-check-ignore

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 20;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        try {
            $present = (int) $this->connection->fetchOne(self::AGENT_ACCOUNT_SQL, ['id' => User::AGENT_ID]);
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.agent_account_unreadable', ['exception' => $e]);

            return new Diagnostic('agent_account', DiagnosticState::Unknown, 'account.system_status.agent_account.unreadable');
        }

        if (0 === $present) {
            return new Diagnostic('agent_account', DiagnosticState::Failed, 'account.system_status.agent_account.missing');
        }

        return new Diagnostic('agent_account', DiagnosticState::Ok, 'account.system_status.agent_account.present');
    }
}
