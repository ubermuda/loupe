<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Service\BetaInviteAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaInviteAccountPurgerTest extends KernelTestCase
{
    /** The users row still exists here, so ON DELETE SET NULL cannot be what clears the columns. */
    public function test_it_clears_the_departing_account_from_both_columns_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user('beta-purge-leaving');
        $staying = $this->user('beta-purge-staying');
        $made = $this->issue($leaving);
        $made->redeem($staying);
        $used = $this->issue($staying);
        $used->redeem($leaving);
        $kept = $this->issue($staying);
        $em->flush();

        $this->purge($leaving);

        $rows = $em->getConnection()->fetchAllAssociativeIndexed('SELECT id, created_by_id, redeemed_by_id FROM beta_invites');
        self::assertSame(['created_by_id' => null, 'redeemed_by_id' => (string) $staying->id], $rows[(string) $made->id]);
        self::assertSame(['created_by_id' => (string) $staying->id, 'redeemed_by_id' => null], $rows[(string) $used->id]);
        self::assertSame(['created_by_id' => (string) $staying->id, 'redeemed_by_id' => null], $rows[(string) $kept->id]);
    }

    public function test_it_purges_a_detached_user(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user('beta-purge-detached');
        $invite = $this->issue($leaving);
        $em->clear();

        $this->purge($leaving);

        self::assertNull($em->getConnection()->fetchOne('SELECT created_by_id FROM beta_invites WHERE id = :id', ['id' => (string) $invite->id]));
    }

    /** A purger that is not tagged never runs, and no test of its statements would notice. */
    public function test_it_is_registered_after_the_project_purger(): void
    {
        self::bootKernel();
        $accountPurger = static::getContainer()->get(AccountPurger::class);
        $purgers = new \ReflectionProperty(AccountPurger::class, 'purgers')->getValue($accountPurger);
        self::assertIsArray($purgers);

        $ordered = array_values(array_map(
            static fn (object $purger): string => $purger::class,
            array_filter($purgers, is_object(...)),
        ));

        self::assertContains(BetaInviteAccountPurger::class, $ordered);
        self::assertGreaterThan(
            array_search(ProjectAccountPurger::class, $ordered, true),
            array_search(BetaInviteAccountPurger::class, $ordered, true),
        );
    }

    private function purge(User $user): void
    {
        new BetaInviteAccountPurger($this->em())->purge($user, new AccountDeletionCleanup());
    }

    private function issue(User $createdBy): BetaInvite
    {
        [$invite] = BetaInvite::issue($createdBy);
        $this->em()->persist($invite);
        $this->em()->flush();

        return $invite;
    }

    private function user(string $prefix): User
    {
        $user = new User('Test', $prefix.'@beta-purge.example.com', 'hashed-password-placeholder');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
