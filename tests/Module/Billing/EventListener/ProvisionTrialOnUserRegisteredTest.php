<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\EventListener\ProvisionTrialOnUserRegistered;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * TrialProvisioner is final and cannot be doubled, so the listener is
 * exercised against a real one whose repository already holds the user's
 * profile — the mocked findOneByUser() call proves ensureProfile() ran for
 * the event's user without any persistence happening.
 */
final class ProvisionTrialOnUserRegisteredTest extends TestCase
{
    public function test_it_ensures_a_billing_profile_for_the_registered_user(): void
    {
        $user = new User(username: 'fresh', fullName: 'Fresh User', email: 'fresh@example.com');
        $profile = new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+14 days'));

        $profiles = $this->createMock(BillingProfileRepository::class);
        $profiles->expects($this->once())->method('findOneByUser')->with($user)->willReturn($profile);

        $listener = new ProvisionTrialOnUserRegistered(
            new TrialProvisioner($profiles, FeatureFlags::service(), $this->createStub(EntityManagerInterface::class)),
        );

        $listener(new UserRegistered($user));
    }
}
