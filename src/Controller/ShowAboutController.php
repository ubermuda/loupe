<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carries the AGPL source offer the sidebar used to point straight at, so the
 * licence terms sit beside the link rather than being implied by it. Public,
 * because a network user must reach the offer without an account — but the
 * build version renders only for signed-in viewers, since telling an anonymous
 * caller which build this is hands them the CVE list to try.
 */
#[Route(
    '/about',
    name: 'app_about',
    methods: ['GET'],
)]
final class ShowAboutController extends AppController
{
    public function __construct(
        #[Autowire(param: 'app.version')]
        private readonly string $version,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('show_about.html.twig', ['version' => $this->version]);
    }
}
