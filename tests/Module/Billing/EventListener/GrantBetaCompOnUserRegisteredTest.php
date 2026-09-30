<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\EventListener\GrantBetaCompOnUserRegistered;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\BetaCompGranter;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SilentAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * GrantCompHandler and BetaCompGranter are final, so a failing grant comes
 * from a real handler whose profile lookup throws.
 */
final class GrantBetaCompOnUserRegisteredTest extends TestCase
{
    public function test_a_failed_grant_is_logged_and_swallowed(): void
    {
        $user = new User(fullName: 'Tester', email: 'tester@example.com');
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $logger = new RecordingLogger();

        $this->listener($this->repositoryReturning($invite), $logger)(new UserRegistered($user));

        self::assertSame(
            [[LogLevel::WARNING, 'billing.beta_comp_failed']],
            array_map(static fn (array $record): array => [$record['level'], $record['message']], $logger->records),
        );
        self::assertSame('profile lookup failed', $logger->records[0]['context']['error']);
    }

    public function test_a_failed_invite_lookup_is_logged_and_swallowed(): void
    {
        $invites = $this->createStub(BetaInviteRepository::class);
        $invites->method('findOneRedeemedBy')->willThrowException(new \RuntimeException('database gone'));
        $logger = new RecordingLogger();

        $this->listener($invites, $logger)(new UserRegistered(new User(fullName: 'Tester', email: 'tester@example.com')));

        self::assertCount(1, $logger->records);
        self::assertSame('billing.beta_comp_failed', $logger->records[0]['message']);
    }

    public function test_a_user_without_an_invite_gets_no_grant(): void
    {
        $logger = new RecordingLogger();

        $this->listener($this->repositoryReturning(null), $logger)(new UserRegistered(new User(fullName: 'Tester', email: 'tester@example.com')));

        self::assertSame([], $logger->records);
    }

    private function repositoryReturning(?BetaInvite $invite): BetaInviteRepository
    {
        $invites = $this->createStub(BetaInviteRepository::class);
        $invites->method('findOneRedeemedBy')->willReturn($invite);

        return $invites;
    }

    private function listener(BetaInviteRepository $invites, RecordingLogger $logger): GrantBetaCompOnUserRegistered
    {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willThrowException(new \RuntimeException('profile lookup failed'));
        $em = $this->createStub(EntityManagerInterface::class);

        $grantComp = new GrantCompHandler(
            new TrialProvisioner($profiles, FeatureFlags::service(), $em, SilentAuditor::create()),
            $em,
            SilentAuditor::create(),
        );

        return new GrantBetaCompOnUserRegistered(
            $invites,
            new BetaCompGranter($grantComp, SilentAuditor::create(), $logger),
            $logger,
        );
    }
}
