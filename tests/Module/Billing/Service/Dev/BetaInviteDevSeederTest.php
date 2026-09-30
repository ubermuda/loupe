<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service\Dev;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\Dev\BetaInviteDevSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BetaInviteDevSeederTest extends KernelTestCase
{
    public function test_it_seeds_one_invite_per_state_and_a_comped_tester_once(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new User('Admin', 'beta-seed-admin@example.com', 'x');
        $em->persist($admin);
        $em->flush();

        $this->seeder()->seed($admin);
        $this->seeder()->seed($admin);

        $tester = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => BetaInviteDevSeeder::TESTER_EMAIL]);
        self::assertInstanceOf(User::class, $tester);
        self::assertNotNull($tester->emailVerifiedAt);

        $invites = self::getContainer()->get(BetaInviteRepository::class);
        $unused = $invites->findOneBy(['note' => 'reddit u/preview-unused']);
        $used = $invites->findOneBy(['note' => 'reddit u/preview-used']);
        $revoked = $invites->findOneBy(['note' => 'reddit u/preview-revoked']);
        self::assertTrue($unused?->isUsable());
        self::assertSame($tester, $used?->redeemedBy);
        self::assertTrue($revoked?->isRevoked());
        self::assertSame(3, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM beta_invites'));

        $profile = self::getContainer()->get(BillingProfileRepository::class)->findOneByUser($tester);
        self::assertNotNull($profile?->currentSubscriptionOfKind(SubscriptionKind::Comp, new \DateTimeImmutable()));
        self::assertCount(1, $profile->subscriptions->filter(static fn ($s): bool => SubscriptionKind::Comp === $s->kind));
    }

    /** The class is dev-only, so the test container holds no instance of it. */
    private function seeder(): BetaInviteDevSeeder
    {
        $container = self::getContainer();

        return new BetaInviteDevSeeder(
            $container->get(EntityManagerInterface::class),
            $container->get(UserRepository::class),
            $container->get(BetaInviteRepository::class),
            $container->get(GrantCompHandler::class),
            $container->get(UserPasswordHasherInterface::class),
            'test-terms',
        );
    }
}
