<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Command\Admin\RevokeCompCommand;
use App\Module\Billing\Command\Admin\RevokeCompHandler;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RevokeCompHandlerTest extends KernelTestCase
{
    public function test_it_ends_the_comp_and_keeps_the_row(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revokeone');
        $comp = static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));
        $compId = $comp->id;

        $revoked = $this->handler()(new RevokeCompCommand($target, $admin));

        self::assertNotNull($revoked->endsAt);
        self::assertFalse($revoked->isCurrent(new \DateTimeImmutable('+1 second')));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(Subscription::class, $compId ?? throw new \LogicException('a flushed comp has an id'));
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertSame(SubscriptionKind::Comp, $reloaded->kind);
        self::assertNotNull($reloaded->endsAt);
    }

    public function test_revoking_without_a_comp_is_a_domain_error(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revoketwo');

        try {
            $this->handler()(new RevokeCompCommand($target, $admin));
            self::fail('Revoking a comp that does not exist should be refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['comp' => 'billing.admin.comp.error.not_comped'], $e->errors);
        }
    }

    public function test_a_revoked_comp_can_be_granted_again(): void
    {
        self::bootKernel();
        [$admin, $target] = $this->actors('revokethree');
        $grant = static::getContainer()->get(GrantCompHandler::class);
        $grant(new GrantCompCommand($target, $admin));
        $this->handler()(new RevokeCompCommand($target, $admin));

        $second = $grant(new GrantCompCommand($target, $admin));

        self::assertTrue($second->isCurrent(new \DateTimeImmutable()));
    }

    /** @return array{User, User} */
    private function actors(string $prefix): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = new User('Admin', $prefix.'-admin@example.com', 'hashed-password-placeholder');
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();

        $target = new User('Target', $prefix.'-target@example.com', 'hashed-password-placeholder');
        $target->emailVerifiedAt = new \DateTimeImmutable();

        $em->persist($admin);
        $em->persist($target);
        $em->flush();

        return [$admin, $target];
    }

    private function handler(): RevokeCompHandler
    {
        return static::getContainer()->get(RevokeCompHandler::class);
    }
}
