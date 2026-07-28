<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Answers the one question a fresh self-hosted instance cannot answer for
 * itself: is the surrounding infrastructure — mail, the messenger worker, the
 * Mercure hub, Stripe — actually wired up, or has it been accepted silently?
 *
 * Every check reports what was *observed*. Where nothing can be observed from a
 * web request the result is SystemCheckState::Unknown with a sentence saying
 * so, never a green tick.
 */
final readonly class CheckSystemStatusHandler
{
    /**
     * Queue name of the `failed` transport in config/packages/messenger.yaml.
     * Everything else in messenger_messages is work the app expects a worker to
     * pick up.
     */
    private const string FAILED_QUEUE_NAME = 'failed';

    /**
     * How long a message may sit available-and-unclaimed before the queue is
     * treated as unattended. Comfortably longer than the gap left by a worker
     * recycling on its time limit, short enough that an operator standing in
     * the install wizard sees the verdict.
     */
    private const int BACKLOG_STALE_SECONDS = 60;

    /**
     * Bounds both network probes. A firewalled SMTP or hub host must make the
     * status page slow, never make it hang.
     */
    private const float PROBE_TIMEOUT_SECONDS = 3.0;

    /**
     * Shipped default of MAILER_FROM_ADDRESS. Deliverable nowhere, so an
     * instance still using it cannot complete a single registration.
     */
    private const string PLACEHOLDER_FROM_ADDRESS = 'noreply@localhost';

    private const string PENDING_BACKLOG_SQL = 'SELECT COUNT(*) AS pending, MIN(available_at) AS oldest FROM messenger_messages WHERE queue_name <> :failed AND delivered_at IS NULL AND available_at <= :now'; // @translation-check-ignore

    private const string FAILED_COUNT_SQL = 'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :failed'; // @translation-check-ignore

    public function __construct(
        private Connection $connection,
        private HttpClientInterface $httpClient,
        private FeatureFlagService $featureFlags,
        private LoggerInterface $logger,

        #[Autowire(service: 'mailer.transport_factory')]
        private Transport $transportFactory,

        #[Autowire('%env(default::MAILER_DSN)%')]
        private ?string $mailerDsn,

        #[Autowire(param: 'app.mailer.from_address')]
        private string $mailerFromAddress,

        #[Autowire('%env(default::MERCURE_URL)%')]
        private ?string $mercureUrl,

        // MERCURE_JWT_SECRET ships without a default on purpose, so it must be
        // read through `default::` — resolving it as a plain env var would make
        // this page fatal on exactly the instance that has not set it yet.
        #[Autowire('%env(default::MERCURE_JWT_SECRET)%')]
        private ?string $mercureJwtSecret,

        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private ?string $stripeSecretKey,

        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        private ?string $stripeWebhookSecret,
    ) {
    }

    public function __invoke(): CheckSystemStatusView
    {
        $checks = [
            $this->checkMailerTransport(),
            $this->checkMailerSender(),
            $this->checkWorker(),
            $this->checkFailedMessages(),
            $this->checkMercure(),
        ];

        // Stripe is only a requirement of an instance that turned billing on;
        // showing it otherwise would report a red cross for a feature the
        // operator deliberately left off.
        if ($this->featureFlags->isEnabled('billing.enabled')) {
            $checks[] = $this->checkStripe();
        }

        $overall = SystemCheckState::Ok;
        foreach ($checks as $check) {
            if ($check->state->severity() > $overall->severity()) {
                $overall = $check->state;
            }
        }

        return new CheckSystemStatusView(checks: $checks, overall: $overall);
    }

    /**
     * Builds the configured transport and, when it speaks SMTP, opens a real
     * connection to it. Anything else is reported as unverifiable rather than
     * assumed working: an API transport would need a live credentialed call to
     * prove anything, and a status page must not spend the operator's quota.
     */
    private function checkMailerTransport(): SystemCheck
    {
        if (null === $this->mailerDsn || '' === $this->mailerDsn) {
            return new SystemCheck('mailer', SystemCheckState::Failed, 'account.system_status.mailer.unset');
        }

        try {
            $transport = $this->transportFactory->fromString($this->mailerDsn);
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.mailer_dsn_invalid', ['exception' => $e]);

            return new SystemCheck('mailer', SystemCheckState::Failed, 'account.system_status.mailer.invalid');
        }

        if ($transport instanceof NullTransport) {
            return new SystemCheck('mailer', SystemCheckState::Failed, 'account.system_status.mailer.null_transport');
        }

        if (!$transport instanceof SmtpTransport) {
            return new SystemCheck('mailer', SystemCheckState::Unknown, 'account.system_status.mailer.unverifiable');
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::PROBE_TIMEOUT_SECONDS);
        }

        try {
            $transport->start();
            $transport->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.smtp_unreachable', ['exception' => $e]);

            return new SystemCheck('mailer', SystemCheckState::Failed, 'account.system_status.mailer.smtp_unreachable');
        }

        return new SystemCheck('mailer', SystemCheckState::Ok, 'account.system_status.mailer.smtp_reachable');
    }

    private function checkMailerSender(): SystemCheck
    {
        if (self::PLACEHOLDER_FROM_ADDRESS === $this->mailerFromAddress || '' === $this->mailerFromAddress) {
            return new SystemCheck('mailer_sender', SystemCheckState::Warning, 'account.system_status.mailer_sender.placeholder');
        }

        return new SystemCheck(
            'mailer_sender',
            SystemCheckState::Ok,
            'account.system_status.mailer_sender.configured',
            ['%address%' => $this->mailerFromAddress],
        );
    }

    /**
     * A running worker leaves no lasting trace, so this measures the only thing
     * that is actually observable from here: whether queued work is being
     * cleared. A backlog that has been available and unclaimed for longer than
     * the threshold proves nothing is consuming; an empty or freshly-filled
     * queue proves nothing either way, and says so.
     */
    private function checkWorker(): SystemCheck
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $row = $this->connection->fetchAssociative(
                self::PENDING_BACKLOG_SQL,
                ['failed' => self::FAILED_QUEUE_NAME, 'now' => $now],
                ['now' => Types::DATETIME_IMMUTABLE],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.queue_unreadable', ['exception' => $e]);

            return new SystemCheck('worker', SystemCheckState::Unknown, 'account.system_status.worker.queue_unreadable');
        }

        $pending = false === $row ? 0 : (int) $row['pending'];
        if (0 === $pending) {
            return new SystemCheck('worker', SystemCheckState::Unknown, 'account.system_status.worker.queue_empty');
        }

        $oldest = $this->parseUtcTimestamp(false === $row ? null : $row['oldest']);
        $waitedSeconds = null === $oldest ? 0 : $now->getTimestamp() - $oldest->getTimestamp();

        if ($waitedSeconds >= self::BACKLOG_STALE_SECONDS) {
            return new SystemCheck(
                'worker',
                SystemCheckState::Failed,
                'account.system_status.worker.backlog_stale',
                ['%count%' => (string) $pending, '%seconds%' => (string) $waitedSeconds],
            );
        }

        return new SystemCheck(
            'worker',
            SystemCheckState::Unknown,
            'account.system_status.worker.backlog_fresh',
            ['%count%' => (string) $pending],
        );
    }

    /**
     * Surfaces the `failed` transport, which is otherwise invisible: messages
     * that exhausted their retries sit there indefinitely and no part of the UI
     * mentions them.
     */
    private function checkFailedMessages(): SystemCheck
    {
        try {
            $failed = (int) $this->connection->fetchOne(
                self::FAILED_COUNT_SQL,
                ['failed' => self::FAILED_QUEUE_NAME],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('account.system_status.failed_queue_unreadable', ['exception' => $e]);

            return new SystemCheck('failed_messages', SystemCheckState::Unknown, 'account.system_status.failed_messages.unreadable');
        }

        if (0 === $failed) {
            return new SystemCheck('failed_messages', SystemCheckState::Ok, 'account.system_status.failed_messages.none');
        }

        return new SystemCheck(
            'failed_messages',
            SystemCheckState::Warning,
            'account.system_status.failed_messages.present',
            ['%count%' => (string) $failed],
        );
    }

    /**
     * Site review saves submissions whether or not a hub is running, so an
     * absent hub degrades the product rather than breaking it — a warning, not
     * a failure. Any HTTP answer at all proves a hub is listening; the endpoint
     * legitimately rejects an unauthenticated, topic-less GET, so only a
     * transport-level error means "not there".
     */
    private function checkMercure(): SystemCheck
    {
        if (null === $this->mercureUrl || '' === $this->mercureUrl
            || null === $this->mercureJwtSecret || '' === $this->mercureJwtSecret) {
            return new SystemCheck('mercure', SystemCheckState::Warning, 'account.system_status.mercure.unconfigured');
        }

        try {
            $statusCode = $this->httpClient
                ->request('GET', $this->mercureUrl, ['timeout' => self::PROBE_TIMEOUT_SECONDS])
                ->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('account.system_status.mercure_unreachable', ['exception' => $e]);

            return new SystemCheck('mercure', SystemCheckState::Warning, 'account.system_status.mercure.unreachable');
        }

        return new SystemCheck(
            'mercure',
            SystemCheckState::Ok,
            'account.system_status.mercure.reachable',
            ['%status%' => (string) $statusCode],
        );
    }

    /**
     * Presence only. Validating the keys would mean calling Stripe from a page
     * an operator opens on a whim, and a self-hosted instance should not make
     * outbound calls nobody asked for.
     */
    private function checkStripe(): SystemCheck
    {
        $missing = [];
        if (null === $this->stripeSecretKey || '' === $this->stripeSecretKey) {
            $missing[] = 'STRIPE_SECRET_KEY';
        }
        if (null === $this->stripeWebhookSecret || '' === $this->stripeWebhookSecret) {
            $missing[] = 'STRIPE_WEBHOOK_SECRET';
        }

        if ([] === $missing) {
            return new SystemCheck('stripe', SystemCheckState::Ok, 'account.system_status.stripe.configured');
        }

        return new SystemCheck(
            'stripe',
            SystemCheckState::Failed,
            'account.system_status.stripe.missing',
            ['%variables%' => implode(', ', $missing)],
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
