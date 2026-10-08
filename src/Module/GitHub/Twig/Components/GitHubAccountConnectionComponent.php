<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubUserConnectionSummary;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The GitHub account section of the Connected apps page. The OAuth module
 * names it in its template only, so it never imports this module.
 *
 * Props: none. The section is about the signed-in user.
 */
#[AsTwigComponent(name: 'GitHubAccountConnection')]
final class GitHubAccountConnectionComponent
{
    public ?GitHubUserConnectionSummary $connection = null;

    public bool $appConfigured = false;

    public function __construct(
        private readonly Security $security,
        private readonly GitHubUserConnectionRepository $gitHubUserConnections,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly ClockInterface $clock,
    ) {
    }

    public function mount(): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->appConfigured = $this->appConfiguration->isConfigured();
        $this->connection = $this->gitHubUserConnections->findSummaryByUser($user);
    }

    public function expired(): bool
    {
        return $this->connection?->isExpired($this->clock->now()) ?? false;
    }

    /** A person with a connection can always remove it, even when the App is gone. */
    public function visible(): bool
    {
        return $this->appConfigured || null !== $this->connection;
    }
}
