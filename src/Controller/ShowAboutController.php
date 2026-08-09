<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BuildIdentity;
use App\Service\UpdateCheck;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carries the AGPL source offer the sidebar used to point straight at, so the
 * licence terms sit beside the link rather than being implied by it. Public,
 * because a network user must reach the offer without an account — but the
 * build and its update check are for signed-in viewers only, since telling an
 * anonymous caller which build this is hands them the CVE list to try.
 */
#[Route(
    '/about',
    name: 'app_about',
    methods: ['GET'],
)]
final class ShowAboutController extends AppController
{
    public function __construct(
        private readonly BuildIdentity $build,
        private readonly UpdateCheck $updateCheck,
    ) {
    }

    public function __invoke(): Response
    {
        // Gated on the user, not just hidden in the template: an anonymous hit
        // would otherwise spend this instance's GitHub rate limit for a card
        // nobody is shown.
        $signedIn = null !== $this->getUser();

        return $this->render('show_about.html.twig', [
            'version' => $signedIn ? $this->build->version : null,
            'update' => $signedIn ? $this->updateCheck->status() : null,
        ]);
    }
}
