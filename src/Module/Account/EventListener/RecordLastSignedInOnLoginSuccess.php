<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final readonly class RecordLastSignedInOnLoginSuccess
{
    /**
     * Only the interactive firewall. The stateless `api` and `mcp` firewalls
     * authenticate on every request, so counting those would turn the stamp
     * into a last-API-call clock.
     */
    private const string INTERACTIVE_FIREWALL = 'main';

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if (self::INTERACTIVE_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $user->lastSignedInAt = new \DateTimeImmutable();
        $this->em->flush();
    }
}
