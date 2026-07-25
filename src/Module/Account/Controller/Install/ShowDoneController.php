<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * By the time this renders, the wizard is closed (a user now exists), so it
 * cannot be gated by InstallationState like the other install routes. Instead
 * it is gated by a one-use session marker set on successful admin creation —
 * any later visit without that marker 404s, keeping the "every /install route
 * 404s once installed" invariant intact.
 */
#[Route('/install/done', name: 'app_install_done', methods: ['GET'])]
final class ShowDoneController extends AppController
{
    public const string SESSION_INSTALL_COMPLETED = 'install_completed';

    public function __invoke(Request $request): Response
    {
        if (true !== $request->getSession()->get(self::SESSION_INSTALL_COMPLETED)) {
            throw $this->createNotFoundException();
        }

        return $this->render('@Account/install/show_done.html.twig');
    }
}
