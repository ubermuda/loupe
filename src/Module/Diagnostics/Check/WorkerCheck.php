<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

/**
 * A running worker leaves no lasting trace, so this measures the only thing
 * that is actually observable from here: whether queued work is being cleared.
 * A backlog the transport would hand out and nobody has taken for longer than
 * the threshold proves nothing is consuming; an empty or freshly-filled queue
 * proves nothing either way, and says so.
 *
 * A message a worker claimed and never finished is the sharpest case, since a
 * worker that dies holding the first administrator's verification email is
 * precisely the lockout this page exists to catch. Such a row keeps a
 * `delivered_at`, so counting only unclaimed rows would report "the queue is
 * empty" — the most reassuring answer there is — over a stuck message. Once the
 * claim outlives the redelivery timeout the transport has written it off and it
 * counts as backlog like any unclaimed row; while the claim is still young it
 * is reported as claimed-and-unfinished, which is honestly unknown rather than
 * either a failure or an all-clear.
 */
final readonly class WorkerCheck implements DiagnosticInterface
{
    /**
     * Splits ready messages into the two groups the check has to tell apart:
     * ones no worker is holding (unclaimed, or a claim the transport has
     * already timed out and would re-offer) and ones a worker claimed recently.
     * The predicate mirrors the transport's own availability query, so
     * "pending" here means exactly what the transport would hand a worker next.
     */
    private const string PENDING_BACKLOG_SQL = 'SELECT COUNT(*) FILTER (WHERE delivered_at IS NULL OR delivered_at < :redeliverLimit) AS pending, MIN(available_at) FILTER (WHERE delivered_at IS NULL OR delivered_at < :redeliverLimit) AS oldest, COUNT(*) FILTER (WHERE delivered_at IS NOT NULL AND delivered_at >= :redeliverLimit) AS claimed FROM messenger_messages WHERE queue_name <> :failed AND available_at <= :now'; // @translation-check-ignore

    /**
     * How long a message may sit available-and-unclaimed before the queue is
     * treated as unattended. Comfortably longer than the gap left by a worker
     * recycling on its time limit, short enough that an operator standing in
     * the install wizard sees the verdict.
     */
    private const int BACKLOG_STALE_SECONDS = 60;

    /**
     * The Doctrine transport's `redeliver_timeout`: how long a claim
     * (`delivered_at`) is honoured before the message is offered to a worker
     * again. This app leaves it at the component default — neither the
     * MESSENGER_TRANSPORT_DSN in `.env` nor config/packages/messenger.yaml sets
     * it — so change this alongside the DSN if that ever stops being true.
     *
     * A claim younger than this is a worker doing its job, or one that just
     * died and whose message no worker will touch yet either way; a claim older
     * than it is one the transport has already given up on, so a message still
     * sitting there means nothing is consuming.
     */
    private const int REDELIVER_TIMEOUT_SECONDS = 3600;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 50;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $row = $this->connection->fetchAssociative(
                self::PENDING_BACKLOG_SQL,
                [
                    'failed' => MessengerQueues::FAILED,
                    'now' => $now,
                    'redeliverLimit' => $now->modify(sprintf('-%d seconds', self::REDELIVER_TIMEOUT_SECONDS)),
                ],
                ['now' => Types::DATETIME_IMMUTABLE, 'redeliverLimit' => Types::DATETIME_IMMUTABLE],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.queue_unreadable', ['exception' => $e]);

            return new Diagnostic('worker', DiagnosticState::Unknown, 'account.system_status.worker.queue_unreadable');
        }

        $pending = false === $row ? 0 : (int) $row['pending'];
        $claimed = false === $row ? 0 : (int) $row['claimed'];

        if (0 === $pending) {
            if ($claimed > 0) {
                return new Diagnostic(
                    'worker',
                    DiagnosticState::Unknown,
                    'account.system_status.worker.claimed_in_flight',
                    ['%count%' => (string) $claimed],
                );
            }

            return new Diagnostic('worker', DiagnosticState::Unknown, 'account.system_status.worker.queue_empty');
        }

        $oldest = $this->parseUtcTimestamp(false === $row ? null : $row['oldest']);
        $waitedSeconds = null === $oldest ? 0 : $now->getTimestamp() - $oldest->getTimestamp();

        if ($waitedSeconds >= self::BACKLOG_STALE_SECONDS) {
            return new Diagnostic(
                'worker',
                DiagnosticState::Failed,
                'account.system_status.worker.backlog_stale',
                ['%count%' => (string) $pending, '%seconds%' => (string) $waitedSeconds],
            );
        }

        return new Diagnostic(
            'worker',
            DiagnosticState::Unknown,
            'account.system_status.worker.backlog_fresh',
            ['%count%' => (string) $pending],
        );
    }

    /**
     * messenger_messages stores UTC wall-clock times in a timezone-less column,
     * so the value has to be re-attached to UTC rather than read in the PHP
     * default timezone.
     */
    private function parseUtcTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
