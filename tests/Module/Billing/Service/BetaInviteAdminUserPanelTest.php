<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Service\BetaInviteAdminUserPanel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BetaInviteAdminUserPanelTest extends KernelTestCase
{
    public function test_it_abstains_for_a_user_who_redeemed_no_invite(): void
    {
        self::bootKernel();
        $user = $this->user('panel-none');
        $this->issue(null, 'unused');

        self::assertNull($this->panel()->panelFor($user));
    }

    public function test_it_shows_the_redemption_date_and_the_note(): void
    {
        self::bootKernel();
        $user = $this->user('panel-tester');
        $invite = $this->issue(null, 'reddit u/foo');
        $invite->redeem($user);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $panel = $this->panel()->panelFor($user);

        self::assertNotNull($panel);
        self::assertSame('@Billing/admin/beta_invite_panel.html.twig', $panel->template);
        self::assertSame(['redeemedAt' => $invite->redeemedAt, 'note' => 'reddit u/foo'], $panel->context);
    }

    private function panel(): BetaInviteAdminUserPanel
    {
        return self::getContainer()->get(BetaInviteAdminUserPanel::class);
    }

    private function issue(?User $createdBy, string $note): BetaInvite
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        [$invite] = BetaInvite::issue($createdBy, $note);
        $em->persist($invite);
        $em->flush();

        return $invite;
    }

    private function user(string $prefix): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Test', $prefix.'@beta-panel.example.com', 'hashed-password-placeholder');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
