<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Surfaces the `failed` transport, which is otherwise invisible: messages that
 * exhausted their retries sit there indefinitely and no part of the UI mentions
 * them.
 */
final readonly class FailedMessagesCheck implements DiagnosticInterface
{
    private const string FAILED_COUNT_SQL = 'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :failed'; // @translation-check-ignore

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 40;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        try {
            $failed = (int) $this->connection->fetchOne(
                self::FAILED_COUNT_SQL,
                ['failed' => MessengerQueues::FAILED],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.failed_queue_unreadable', ['exception' => $e]);

            return new Diagnostic('failed_messages', DiagnosticState::Unknown, 'account.system_status.failed_messages.unreadable');
        }

        if (0 === $failed) {
            return new Diagnostic('failed_messages', DiagnosticState::Ok, 'account.system_status.failed_messages.none');
        }

        return new Diagnostic(
            'failed_messages',
            DiagnosticState::Warning,
            'account.system_status.failed_messages.present',
            ['%count%' => $failed],
        );
    }
}
