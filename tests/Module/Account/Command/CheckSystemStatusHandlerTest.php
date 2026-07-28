<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\CheckSystemStatusView;
use App\Module\Account\Command\SystemCheck;
use App\Module\Account\Command\SystemCheckState;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\SystemStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Runs against the real test database so the backlog query — including the way
 * messenger_messages stores UTC wall-clock times in a timezone-less column — is
 * exercised as written rather than mocked away.
 */
final class CheckSystemStatusHandlerTest extends KernelTestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function test_the_shipped_null_mailer_is_a_failure_not_a_pass(): void
    {
        $view = (SystemStatus::handler($this->connection, mailerDsn: 'null://null'))();

        $check = self::check($view, 'mailer');
        self::assertSame(SystemCheckState::Failed, $check->state);
        self::assertSame('account.system_status.mailer.null_transport', $check->detail);
    }

    public function test_a_non_smtp_transport_is_reported_as_unverifiable_not_ok(): void
    {
        $view = (SystemStatus::handler($this->connection, mailerDsn: 'sendmail://default'))();

        self::assertSame(SystemCheckState::Unknown, self::check($view, 'mailer')->state);
    }

    public function test_an_unparseable_dsn_is_a_failure(): void
    {
        $view = (SystemStatus::handler($this->connection, mailerDsn: 'not-a-dsn'))();

        $check = self::check($view, 'mailer');
        self::assertSame(SystemCheckState::Failed, $check->state);
        self::assertSame('account.system_status.mailer.invalid', $check->detail);
    }

    public function test_the_placeholder_sender_address_is_flagged(): void
    {
        $view = (SystemStatus::handler($this->connection, mailerFromAddress: 'noreply@localhost'))();

        self::assertSame(SystemCheckState::Warning, self::check($view, 'mailer_sender')->state);
    }

    public function test_a_real_sender_address_passes_and_is_echoed_back(): void
    {
        $view = (SystemStatus::handler($this->connection, mailerFromAddress: 'hello@example.com'))();

        $check = self::check($view, 'mailer_sender');
        self::assertSame(SystemCheckState::Ok, $check->state);
        self::assertSame(['%address%' => 'hello@example.com'], $check->detailParameters);
    }

    public function test_an_empty_queue_is_unknown_because_it_proves_nothing(): void
    {
        $view = (SystemStatus::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(SystemCheckState::Unknown, $check->state);
        self::assertSame('account.system_status.worker.queue_empty', $check->detail);
    }

    public function test_a_freshly_queued_message_is_still_unknown(): void
    {
        $this->enqueue('default', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $view = (SystemStatus::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(SystemCheckState::Unknown, $check->state);
        self::assertSame('account.system_status.worker.backlog_fresh', $check->detail);
    }

    public function test_a_message_nobody_claimed_for_minutes_proves_no_worker_is_consuming(): void
    {
        $this->enqueue('default', new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')));

        $view = (SystemStatus::handler($this->connection))();

        $check = self::check($view, 'worker');
        self::assertSame(SystemCheckState::Failed, $check->state);
        self::assertSame('account.system_status.worker.backlog_stale', $check->detail);
        self::assertSame('1', $check->detailParameters['%count%']);
        self::assertGreaterThanOrEqual(300, (int) $check->detailParameters['%seconds%']);
    }

    public function test_messages_parked_in_the_failed_transport_do_not_count_as_a_backlog(): void
    {
        $this->enqueue('failed', new \DateTimeImmutable('-5 minutes', new \DateTimeZone('UTC')));

        $view = (SystemStatus::handler($this->connection))();

        // Guard: without it, "the worker check is not Failed" would also pass
        // on a run where the row was never inserted.
        $failedMessages = self::check($view, 'failed_messages');
        self::assertSame(SystemCheckState::Warning, $failedMessages->state);
        self::assertSame('1', $failedMessages->detailParameters['%count%']);

        self::assertSame(SystemCheckState::Unknown, self::check($view, 'worker')->state);
    }

    public function test_an_empty_failed_transport_passes(): void
    {
        $view = (SystemStatus::handler($this->connection))();

        self::assertSame(SystemCheckState::Ok, self::check($view, 'failed_messages')->state);
    }

    public function test_an_unconfigured_mercure_hub_warns_without_calling_anything(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request expected'));

        $view = (SystemStatus::handler($this->connection, httpClient: $client))();

        $check = self::check($view, 'mercure');
        self::assertSame(SystemCheckState::Warning, $check->state);
        self::assertSame('account.system_status.mercure.unconfigured', $check->detail);
    }

    public function test_any_http_answer_from_the_hub_counts_as_present(): void
    {
        // The hub legitimately rejects an unauthenticated, topic-less GET, so
        // a 401 is proof it is listening — gating on 2xx would be a false red.
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        $view = (SystemStatus::handler(
            $this->connection,
            mercureUrl: 'http://mercure/.well-known/mercure',
            mercureJwtSecret: 'a-secret-that-is-long-enough-for-hs256',
            httpClient: $client,
        ))();

        $check = self::check($view, 'mercure');
        self::assertSame(SystemCheckState::Ok, $check->state);
        self::assertSame(['%status%' => '401'], $check->detailParameters);
    }

    public function test_a_hub_that_cannot_be_reached_warns_rather_than_fails(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('name or service not known'));

        $view = (SystemStatus::handler(
            $this->connection,
            mercureUrl: 'http://mercure/.well-known/mercure',
            mercureJwtSecret: 'a-secret-that-is-long-enough-for-hs256',
            httpClient: $client,
        ))();

        $check = self::check($view, 'mercure');
        self::assertSame(SystemCheckState::Warning, $check->state);
        self::assertSame('account.system_status.mercure.unreachable', $check->detail);
    }

    public function test_stripe_is_not_checked_when_billing_is_off(): void
    {
        $view = (SystemStatus::handler($this->connection))();

        self::assertSame([], array_values(array_filter(
            $view->checks,
            static fn (SystemCheck $check): bool => 'stripe' === $check->key,
        )));
    }

    public function test_billing_without_stripe_credentials_is_a_failure(): void
    {
        $view = (SystemStatus::handler(
            $this->connection,
            featureFlags: FeatureFlags::service(['billing.enabled' => true]),
        ))();

        $check = self::check($view, 'stripe');
        self::assertSame(SystemCheckState::Failed, $check->state);
        self::assertSame(
            ['%variables%' => 'STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET'],
            $check->detailParameters,
        );
    }

    public function test_billing_with_both_stripe_keys_passes(): void
    {
        $view = (SystemStatus::handler(
            $this->connection,
            stripeSecretKey: 'sk_test_dummy',
            stripeWebhookSecret: 'whsec_test',
            featureFlags: FeatureFlags::service(['billing.enabled' => true]),
        ))();

        self::assertSame(SystemCheckState::Ok, self::check($view, 'stripe')->state);
    }

    public function test_the_overall_state_is_the_worst_of_the_checks(): void
    {
        // A null mail transport is a failure, so nothing milder can win.
        $view = (SystemStatus::handler($this->connection, mailerDsn: 'null://null'))();

        self::assertSame(SystemCheckState::Failed, $view->overall);
    }

    private function enqueue(string $queueName, \DateTimeImmutable $availableAt): void
    {
        $this->connection->insert('messenger_messages', [
            'body' => '{}',
            'headers' => '{}',
            'queue_name' => $queueName,
            'created_at' => $availableAt,
            'available_at' => $availableAt,
        ], [
            'created_at' => Types::DATETIME_IMMUTABLE,
            'available_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private static function check(CheckSystemStatusView $view, string $key): SystemCheck
    {
        foreach ($view->checks as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        throw new \LogicException(sprintf('No "%s" check in the status view.', $key));
    }
}
