<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * Fixture helpers for the billing WebTestCases: a verified user, a billing
 * profile in a chosen state, and the feature-flag rows the real
 * DoctrineFeatureFlagReader will read during the request.
 */
final readonly class BillingScenario
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    private function em(): EntityManagerInterface
    {
        return $this->container->get(EntityManagerInterface::class);
    }

    public function verifiedUser(string $username): User
    {
        $user = new User($username, ucfirst($username), $username.'@example.com', 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();

        $em = $this->em();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function profile(User $user, \DateTimeImmutable $trialEndsAt): BillingProfile
    {
        $profile = new BillingProfile($user, trialEndsAt: $trialEndsAt);

        $em = $this->em();
        $em->persist($profile);
        $em->flush();

        return $profile;
    }

    public function enableBilling(bool $enabled = true): void
    {
        $this->flag('billing.enabled', FeatureFlagType::Bool, $enabled);
    }

    public function priceFlag(string $priceId): void
    {
        $this->flag('billing.stripe_price_id', FeatureFlagType::Select, $priceId);
    }

    private function flag(string $name, FeatureFlagType $type, bool|int|string $value): void
    {
        $em = $this->em();
        $em->persist(new FeatureFlag($name, $type, $value));
        $em->flush();
    }
}
