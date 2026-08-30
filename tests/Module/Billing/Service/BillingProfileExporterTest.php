<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\BillingProfileExporter;
use App\Tests\Support\BillingGrants;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class BillingProfileExporterTest extends TestCase
{
    public function test_exports_the_stripe_identifiers_and_dates(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('2026-01-31T00:00:00+00:00'));
        $profile->stripeCustomerId = 'cus_123';
        BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('2026-02-28T00:00:00+00:00'), 'sub_123');
        $profile->lastStripeEventId = 'evt_secret';

        $row = iterator_to_array(new BillingProfileExporter($this->repositoryReturning($profile))->export($user));

        self::assertSame('cus_123', $row['stripeCustomerId']);
        self::assertArrayHasKey('createdAt', $row);
        self::assertArrayNotHasKey('lastStripeEventId', $row);

        self::assertIsArray($row['subscriptions']);
        self::assertCount(2, $row['subscriptions']);
        self::assertSame('trial', $row['subscriptions'][0]['kind']);
        self::assertSame('2026-01-31T00:00:00+00:00', $row['subscriptions'][0]['endsAt']);
        self::assertSame('stripe', $row['subscriptions'][1]['kind']);
        self::assertSame('sub_123', $row['subscriptions'][1]['stripeSubscriptionId']);
        self::assertSame('active', $row['subscriptions'][1]['stripeStatus']);
        self::assertSame('2026-02-28T00:00:00+00:00', $row['subscriptions'][1]['endsAt']);
    }

    public function test_exports_nothing_when_the_user_has_no_profile(): void
    {
        $exporter = new BillingProfileExporter($this->repositoryReturning(null));

        self::assertSame([], iterator_to_array($exporter->export(new User('Bob B', 'bob@example.com', 'x'))));
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
