<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Migration;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PaywallGate;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\SilentAuditor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260830152618;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once dirname(__DIR__, 4).'/migrations/Version20260830152618.php';

/**
 * The backfill runs against a profile of every shape the old status model could
 * hold, and each one must come out with the access it had before. Access is
 * read through PaywallGate rather than off the rows, because the rule is what
 * has to survive the move.
 *
 * The migration set has already contracted the old columns away by the time the
 * suite boots, so the test puts them back. Postgres rolls DDL back with the
 * rest of the transaction dama wraps each test in.
 */
final class BackfillSubscriptionsTest extends KernelTestCase
{
    /** @return iterable<string, array{array<string, ?string>, bool}> */
    public static function profileShapes(): iterable
    {
        yield 'a running trial' => [
            ['status' => 'trialing', 'trial_ends_at' => '+5 days'],
            true,
        ];

        yield 'a lapsed trial' => [
            ['status' => 'trialing', 'trial_ends_at' => '-1 day'],
            false,
        ];

        yield 'an active subscription' => [
            ['status' => 'active', 'trial_ends_at' => '-30 days', 'current_period_end' => '+30 days'],
            true,
        ];

        yield 'an active subscription with no known period end' => [
            ['status' => 'active', 'trial_ends_at' => '-30 days'],
            true,
        ];

        yield 'an unpaid subscription past its period' => [
            ['status' => 'past-due', 'trial_ends_at' => '-30 days', 'current_period_end' => '-1 day'],
            false,
        ];

        yield 'an unpaid subscription with no known period end' => [
            ['status' => 'past-due', 'trial_ends_at' => '-30 days'],
            false,
        ];

        yield 'a mid-period cancel' => [
            [
                'status' => 'canceled',
                'trial_ends_at' => '-30 days',
                'current_period_end' => '+5 days',
                'last_stripe_event_type' => BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE,
            ],
            true,
        ];

        yield 'a cancellation whose paid period is over' => [
            [
                'status' => 'canceled',
                'trial_ends_at' => '-30 days',
                'current_period_end' => '-1 day',
                'last_stripe_event_type' => BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE,
            ],
            false,
        ];

        yield 'a cancellation with no known period end' => [
            [
                'status' => 'canceled',
                'trial_ends_at' => '-30 days',
                'last_stripe_event_type' => BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE,
            ],
            false,
        ];

        yield 'an incomplete subscription Stripe stamped a future period on' => [
            [
                'status' => 'canceled',
                'trial_ends_at' => '-30 days',
                'current_period_end' => '+5 days',
                'last_stripe_event_type' => 'customer.subscription.updated',
            ],
            false,
        ];

        yield 'a cancellation with nothing recorded' => [
            ['status' => 'canceled', 'trial_ends_at' => '-30 days'],
            false,
        ];
    }

    /** @param array<string, ?string> $columns */
    #[DataProvider('profileShapes')]
    public function test_the_backfill_preserves_the_access_each_profile_had(array $columns, bool $expected): void
    {
        self::bootKernel();
        $this->restoreLegacyColumns();

        $user = $this->profileFor('backfilled');
        $this->writeLegacyState($user, $columns);
        $this->runBackfill();

        self::assertSame($expected, $this->gate()->allows($user));
    }

    public function test_every_profile_gets_exactly_one_grant_of_the_right_kind(): void
    {
        self::bootKernel();
        $this->restoreLegacyColumns();

        $trialing = $this->profileFor('shapetrial');
        $this->writeLegacyState($trialing, ['status' => 'trialing', 'trial_ends_at' => '+5 days']);
        $subscriber = $this->profileFor('shapestripe');
        $this->writeLegacyState($subscriber, [
            'status' => 'active',
            'trial_ends_at' => '-30 days',
            'current_period_end' => '+30 days',
            'stripe_subscription_id' => 'sub_backfilled',
        ]);

        $this->runBackfill();

        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM subscriptions'));
        self::assertSame(['trial', null, null], $this->grantOf($trialing));
        self::assertSame(['stripe', 'sub_backfilled', 'active'], $this->grantOf($subscriber));
    }

    /** @return array{string, ?string, ?string} kind, Stripe subscription id, Stripe status */
    private function grantOf(User $user): array
    {
        $row = $this->connection()->fetchAssociative(
            <<<'SQL'
                SELECT s.kind, s.stripe_subscription_id, s.stripe_status
                FROM subscriptions s
                JOIN billing_profiles p ON p.id = s.billing_profile_id
                WHERE p.user_id = :id
                SQL,
            ['id' => (string) $user->id],
        );
        self::assertIsArray($row);

        return [(string) $row['kind'], $row['stripe_subscription_id'], $row['stripe_status']];
    }

    private function restoreLegacyColumns(): void
    {
        $connection = $this->connection();
        foreach ([
            "ADD status VARCHAR(20) NOT NULL DEFAULT 'trialing'",
            'ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL',
            'ADD current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
            'ADD trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()',
            'ADD survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
            'ADD cancel_survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
        ] as $clause) {
            $connection->executeStatement('ALTER TABLE billing_profiles '.$clause);
        }
    }

    private function profileFor(string $username): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(ucfirst($username), $username.'@example.com', 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->persist(new BillingProfile($user));
        $em->flush();

        return $user;
    }

    /** @param array<string, ?string> $columns */
    private function writeLegacyState(User $user, array $columns): void
    {
        $assignments = [];
        $parameters = ['id' => (string) $user->id];
        foreach ($columns as $column => $value) {
            $assignments[] = sprintf('%s = :%s', $column, $column);
            $parameters[$column] = match ($column) {
                'trial_ends_at', 'current_period_end' => new \DateTimeImmutable((string) $value)->format('Y-m-d H:i:s'),
                default => $value,
            };
        }

        $this->connection()->executeStatement(
            sprintf('UPDATE billing_profiles SET %s WHERE user_id = :id', implode(', ', $assignments)),
            $parameters,
        );
    }

    private function runBackfill(): void
    {
        $connection = $this->connection();
        $migration = new Version20260830152618($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function gate(): PaywallGate
    {
        $container = self::getContainer();

        return new PaywallGate(
            FeatureFlags::service(['billing.enabled' => true]),
            new TrialProvisioner(
                $container->get(BillingProfileRepository::class),
                FeatureFlags::service(),
                $container->get(EntityManagerInterface::class),
                SilentAuditor::create(),
            ),
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
