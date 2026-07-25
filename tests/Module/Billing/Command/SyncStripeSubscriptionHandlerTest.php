<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Module\Account\Entity\User;
use App\Module\Billing\Command\SyncStripeSubscriptionCommand;
use App\Module\Billing\Command\SyncStripeSubscriptionHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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

    private function handler(?BillingProfile $profile): SyncStripeSubscriptionHandler
    {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByStripeCustomerId')->willReturn($profile);

        return new SyncStripeSubscriptionHandler($profiles, $this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    /** @param non-empty-string $eventId */
    private function command(string $status, string $eventCreatedAt = 'now', string $eventId = 'evt_1'): SyncStripeSubscriptionCommand
    {
        return new SyncStripeSubscriptionCommand(
            stripeEventId: $eventId,
            stripeCustomerId: 'cus_123',
            stripeSubscriptionId: 'sub_123',
            stripeStatus: $status,
            currentPeriodEnd: new \DateTimeImmutable('+30 days'),
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

        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_deleted'));
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

    public function test_a_newer_event_is_applied(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_first'));
        $handler($this->command('canceled', '2026-07-25 12:00:06', 'evt_second'));

        self::assertSame(BillingStatus::Canceled, $profile->status);
    }
}
