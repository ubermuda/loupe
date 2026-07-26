<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Billing\Command\SyncStripeSubscriptionCommand;
use App\Module\Billing\Command\SyncStripeSubscriptionHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TransactionalEntityManagerStub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class SyncStripeSubscriptionHandlerTest extends TestCase
{
    private function profile(): BillingProfile
    {
        $user = new User(username: 'synced', fullName: 'Synced User', email: 'synced@example.com', password: 'irrelevant');
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        return $profile;
    }

    private function handler(
        ?BillingProfile $profile,
        ?WaitlistEntry $waitlistEntry = null,
        ?LoggerInterface $logger = null,
    ): SyncStripeSubscriptionHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByStripeCustomerId')->willReturn($profile);

        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByEmail')->willReturn($waitlistEntry);

        return new SyncStripeSubscriptionHandler(
            $profiles,
            $waitlistEntries,
            TransactionalEntityManagerStub::configure($this->createStub(EntityManagerInterface::class)),
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @param non-empty-string $eventId
     * @param non-empty-string $eventType
     */
    private function command(
        string $status,
        string $eventCreatedAt = 'now',
        string $eventId = 'evt_1',
        string $eventType = 'customer.subscription.updated',
        ?string $currentPeriodEnd = '+30 days',
    ): SyncStripeSubscriptionCommand {
        return new SyncStripeSubscriptionCommand(
            stripeEventId: $eventId,
            stripeCustomerId: 'cus_123',
            stripeSubscriptionId: 'sub_123',
            stripeStatus: $status,
            stripeEventType: $eventType,
            currentPeriodEnd: null === $currentPeriodEnd ? null : new \DateTimeImmutable($currentPeriodEnd),
            eventCreatedAt: new \DateTimeImmutable($eventCreatedAt),
        );
    }

    /** @return iterable<string, array{string, BillingStatus}> */
    public static function statuses(): iterable
    {
        yield 'active' => ['active', BillingStatus::Active];
        yield 'past_due' => ['past_due', BillingStatus::PastDue];
        yield 'canceled' => ['canceled', BillingStatus::Canceled];
    }

    #[DataProvider('statuses')]
    public function test_subscription_state_is_written_onto_the_profile(string $stripeStatus, BillingStatus $expected): void
    {
        $profile = $this->profile();

        ($this->handler($profile))($this->command($stripeStatus));

        self::assertSame($expected, $profile->status);
        self::assertSame('sub_123', $profile->stripeSubscriptionId);
        self::assertNotNull($profile->currentPeriodEnd);
        self::assertNotNull($profile->lastStripeEventAt);
    }

    public function test_an_unknown_customer_is_ignored_without_throwing(): void
    {
        ($this->handler(null))($this->command('active'));

        $this->expectNotToPerformAssertions();
    }

    public function test_an_out_of_order_event_does_not_resurrect_a_cancelled_subscription(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_deleted', 'customer.subscription.deleted'));
        $handler($this->command('active', '2026-07-25 12:00:01', 'evt_older_update'));

        self::assertSame(BillingStatus::Canceled, $profile->status);
    }

    public function test_a_replayed_event_is_a_no_op(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_same'));
        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_same'));

        self::assertSame(BillingStatus::Active, $profile->status);
    }

    /**
     * Stripe's `created` has one-second resolution, so a `created` event and the
     * `updated` event right behind it legitimately share a timestamp. Only the
     * id can tell them apart, and both must land.
     */
    public function test_a_distinct_event_in_the_same_second_is_applied(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('incomplete', '2026-07-25 12:00:05', 'evt_created'));
        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_updated'));

        self::assertSame(BillingStatus::Active, $profile->status);
    }

    /**
     * The dangerous same-second case: a cancellation and an older `updated`
     * share a second and Stripe delivers them out of order. Access must not come
     * back.
     */
    public function test_a_same_second_update_never_undoes_a_cancellation(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_deleted', 'customer.subscription.deleted'));
        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_stale_update'));

        self::assertSame(BillingStatus::Canceled, $profile->status);
    }

    public function test_a_newer_event_is_applied(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_first'));
        $handler($this->command('canceled', '2026-07-25 12:00:06', 'evt_second'));

        self::assertSame(BillingStatus::Canceled, $profile->status);
    }

    public function test_activation_of_a_disabled_account_reenables_it_and_resets_the_cancel_survey_marker(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $profile->cancelSurveySentAt = new \DateTimeImmutable('-1 day');
        $logger = new RecordingLogger();

        ($this->handler($profile, logger: $logger))($this->command('active'));

        self::assertNull($profile->user->disabledAt);
        self::assertNull($profile->cancelSurveySentAt);
        self::assertContains('billing.account.reenabled', $logger->messages);
    }

    public function test_activation_of_a_disabled_account_converts_a_matching_waitlist_entry(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $entry = new WaitlistEntry('synced@example.com');

        ($this->handler($profile, $entry))($this->command('active'));

        self::assertNotNull($entry->convertedAt);
    }

    public function test_activation_does_not_reconvert_an_already_converted_waitlist_entry(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $entry = new WaitlistEntry('synced@example.com');
        $convertedAt = new \DateTimeImmutable('2026-01-01 09:00:00');
        $entry->convertedAt = $convertedAt;

        ($this->handler($profile, $entry))($this->command('active'));

        self::assertSame($convertedAt, $entry->convertedAt);
    }

    /** @return iterable<string, array{?string}> */
    public static function lapsedPeriodEnds(): iterable
    {
        yield 'past' => ['-1 hour'];
        yield 'null' => [null];
    }

    #[DataProvider('lapsedPeriodEnds')]
    public function test_a_cancellation_whose_paid_period_is_over_disables_the_account(?string $currentPeriodEnd): void
    {
        $profile = $this->profile();
        $logger = new RecordingLogger();

        ($this->handler($profile, logger: $logger))(
            $this->command('canceled', currentPeriodEnd: $currentPeriodEnd),
        );

        self::assertNotNull($profile->user->disabledAt);
        self::assertContains('billing.account.disabled_on_cancel', $logger->messages);
    }

    public function test_a_cancellation_with_a_future_period_end_keeps_the_account_enabled(): void
    {
        $profile = $this->profile();

        ($this->handler($profile))($this->command('canceled', currentPeriodEnd: '+10 days'));

        self::assertNull($profile->user->disabledAt);
    }
}
