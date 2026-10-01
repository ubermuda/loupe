<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Repository;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaInviteRepositoryTest extends KernelTestCase
{
    public function test_find_one_redeemed_by_returns_the_first_redemption(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tester = new User(fullName: 'Tester', email: 'repo-tester@example.com', password: 'x');
        $em->persist($tester);

        // Persisted newest first, so insertion order cannot pass for the answer.
        $later = $this->redeemed($tester, new \DateTimeImmutable('-1 day'));
        $first = $this->redeemed($tester, new \DateTimeImmutable('-30 days'));
        $em->persist($later);
        $em->persist($first);
        $em->flush();
        $em->clear();

        $found = self::getContainer()->get(BetaInviteRepository::class)->findOneRedeemedBy(
            $em->find(User::class, $tester->id) ?? throw new \LogicException('persisted above'),
        );

        self::assertSame($first->id?->toRfc4122(), $found?->id?->toRfc4122());
    }

    private function redeemed(User $user, \DateTimeImmutable $at): BetaInvite
    {
        [$invite] = BetaInvite::issue(null);
        $invite->redeem($user);
        $invite->redeemedAt = $at;

        return $invite;
    }
}
