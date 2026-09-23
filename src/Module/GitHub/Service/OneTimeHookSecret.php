<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Carries a new hook secret across the redirect to the next render only. The
 * flash type is not one that base.html.twig prints as a notice.
 */
final readonly class OneTimeHookSecret
{
    private const string FLASH_PREFIX = 'github_hook_secret.';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function put(Project $project, string $secret): void
    {
        $this->flashBag()?->set(self::FLASH_PREFIX.$project->id, [$secret]);
    }

    public function take(Project $project): ?string
    {
        $secret = $this->flashBag()?->get(self::FLASH_PREFIX.$project->id)[0] ?? null;

        return \is_string($secret) ? $secret : null;
    }

    private function flashBag(): ?FlashBagInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        return $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag() : null;
    }
}
