<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\BillingProfileExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class BillingProfileExporterTest extends TestCase
{
    public function test_exports_the_stripe_identifiers_and_dates(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $profile = new BillingProfile($user, new \DateTimeImmutable('2026-01-31T00:00:00+00:00'));
        $profile->status = BillingStatus::Active;
        $profile->stripeCustomerId = 'cus_123';
        $profile->stripeSubscriptionId = 'sub_123';
        $profile->currentPeriodEnd = new \DateTimeImmutable('2026-02-28T00:00:00+00:00');
        $profile->lastStripeEventId = 'evt_secret';

        $row = new BillingProfileExporter($this->repositoryReturning($profile))->export($user);

        self::assertSame('active', $row['status']);
        self::assertSame('cus_123', $row['stripeCustomerId']);
        self::assertSame('sub_123', $row['stripeSubscriptionId']);
        self::assertSame('2026-01-31T00:00:00+00:00', $row['trialEndsAt']);
        self::assertSame('2026-02-28T00:00:00+00:00', $row['currentPeriodEnd']);
        self::assertArrayHasKey('createdAt', $row);
        self::assertArrayNotHasKey('lastStripeEventId', $row);
    }

    public function test_exports_nothing_when_the_user_has_no_profile(): void
    {
        $exporter = new BillingProfileExporter($this->repositoryReturning(null));

        self::assertSame([], $exporter->export(new User('Bob B', 'bob@example.com', 'x')));
        self::assertSame('billing_profile.json', $exporter->filename());
    }

    private function repositoryReturning(?BillingProfile $profile): BillingProfileRepository
    {
        /** @var BillingProfileRepository&Stub $repo */
        $repo = $this->createStub(BillingProfileRepository::class);
        $repo->method('findOneByUser')->willReturn($profile);

        return $repo;
    }
}
