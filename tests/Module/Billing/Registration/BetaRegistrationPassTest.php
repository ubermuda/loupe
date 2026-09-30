<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Registration;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Registration\BetaRegistrationPass;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaRegistrationPassTest extends KernelTestCase
{
    public function test_a_usable_token_is_valid_and_redeems_for_the_user(): void
    {
        self::bootKernel();
        $token = $this->seed();
        $user = new User(fullName: 'Tester', email: 'pass-tester@example.com');

        self::assertTrue($this->pass()->isValid($token));
        self::assertTrue($this->redeem($token, $user));
        self::assertFalse($this->pass()->isValid($token));
    }

    public function test_an_unknown_token_is_neither_valid_nor_redeemed(): void
    {
        self::bootKernel();

        self::assertFalse($this->pass()->isValid('unknown'));
        self::assertFalse($this->redeem('unknown', new User(fullName: 'Tester', email: 'pass-unknown@example.com')));
    }

    /** The row changes behind the loaded entity, as a concurrent revocation would. */
    public function test_redeem_rechecks_the_locked_row_and_refuses_a_revocation_it_had_not_seen(): void
    {
        self::bootKernel();
        $token = $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE beta_invites SET revoked_at = NOW() WHERE token_hash = ?',
            [BetaInvite::hashToken($token)],
        );

        self::assertFalse($this->redeem($token, new User(fullName: 'Tester', email: 'pass-late@example.com')));
    }

    private function seed(): string
    {
        [$invite, $token] = BetaInvite::issue(null);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($invite);
        $em->flush();

        return $token;
    }

    private function redeem(string $token, User $user): bool
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Persisted inside the transaction, as both sign-up handlers do, so the
        // flush that commits the redemption also inserts the user.
        return $em->wrapInTransaction(function () use ($em, $token, $user): bool {
            $redeemed = $this->pass()->redeem($token, $user);
            $em->persist($user);

            return $redeemed;
        });
    }

    private function pass(): BetaRegistrationPass
    {
        return self::getContainer()->get(BetaRegistrationPass::class);
    }
}
