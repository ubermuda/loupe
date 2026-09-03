<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class TrialProvisionerTest extends KernelTestCase
{
    private RecordingAuditor $audit;

    private function provisioner(int $trialDays): TrialProvisioner
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());

        return new TrialProvisioner(
            static::getContainer()->get(BillingProfileRepository::class),
            FeatureFlags::service(['billing.trial_days' => $trialDays]),
            static::getContainer()->get(EntityManagerInterface::class),
            $this->audit->auditor,
        );
    }

    private function verifiedUser(string $username): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(ucfirst($username), $username.'@example.com', 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_first_call_creates_one_profile_with_the_flag_configured_trial(): void
    {
        self::bootKernel();
        $user = $this->verifiedUser('trialone');

        $profile = $this->provisioner(30)->ensureProfile($user);

        self::assertSame($user, $profile->user);
        $trialEndsAt = $this->trialEndsAt($profile);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), $trialEndsAt);
        self::assertLessThan(new \DateTimeImmutable('+31 days'), $trialEndsAt);
        self::assertSame(1, $this->countProfiles());
        self::assertSame(1, $this->countSubscriptions());
    }

    public function test_second_call_returns_the_same_profile_and_creates_no_row(): void
    {
        self::bootKernel();
        $user = $this->verifiedUser('trialtwo');
        $provisioner = $this->provisioner(30);

        $first = $provisioner->ensureProfile($user);
        $second = $provisioner->ensureProfile($user);

        self::assertSame((string) $first->id, (string) $second->id);
        self::assertSame(1, $this->countProfiles());
    }

    public function test_trial_length_falls_back_to_the_default_when_the_flag_is_unset(): void
    {
        self::bootKernel();
        $user = $this->verifiedUser('trialdefault');

        $provisioner = new TrialProvisioner(
            static::getContainer()->get(BillingProfileRepository::class),
            FeatureFlags::service(),
            static::getContainer()->get(EntityManagerInterface::class),
            (new RecordingAuditor(new NullAuditActorProvider()))->auditor,
        );
        $profile = $provisioner->ensureProfile($user);

        $expected = new \DateTimeImmutable(sprintf('+%d days', TrialProvisioner::DEFAULT_TRIAL_DAYS));
        self::assertGreaterThan($expected->modify('-1 day'), $this->trialEndsAt($profile));
        self::assertLessThan($expected->modify('+1 day'), $this->trialEndsAt($profile));
    }

    /**
     * PaywallGate calls this on every paywalled request, so a record on the
     * read that finds an existing profile would bury the one grant that matters.
     */
    public function test_the_grant_is_recorded_once_and_a_second_call_records_nothing(): void
    {
        self::bootKernel();
        $user = $this->verifiedUser('trialaudit');
        $provisioner = $this->provisioner(30);

        $provisioner->ensureProfile($user);

        $record = $this->audit->record('billing.trial_granted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);
        self::assertSame([
            'userId' => (string) $user->id,
            'trialDays' => 30,
        ], $record->context);

        $this->audit->forget();
        $provisioner->ensureProfile($user);

        self::assertSame([], $this->audit->operations());
    }

    private function trialEndsAt(BillingProfile $profile): \DateTimeImmutable
    {
        return $profile->latestSubscriptionOfKind(SubscriptionKind::Trial)->endsAt
            ?? throw new \LogicException('a provisioned profile always has a trial grant');
    }

    private function countSubscriptions(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM subscriptions');
    }

    /**
     * The concurrent-first-request branch (two requests racing to insert, the
     * loser catching the unique violation and re-reading) cannot be exercised
     * here: dama wraps each test in a single connection's transaction, so two
     * overlapping database transactions are not expressible. The unique FK on
     * user_id is what makes the branch correct; these sequential tests are the
     * regression guard for the observable behaviour — exactly one profile.
     */
    private function countProfiles(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM billing_profiles');
    }
}
