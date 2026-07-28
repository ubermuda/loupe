<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use App\Module\Account\Command\CheckSystemStatusHandler;
use App\Module\Account\Service\InstallAccessGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sits between the feature-flag step and admin creation, so the checks it runs
 * cover only what the operator just turned on.
 *
 * Its reason for existing is the trap directly ahead of it: the wizard creates
 * an unverified administrator and emails them a verification link, and an
 * instance whose mail or worker is not working delivers nothing, leaving the
 * operator locked out of their own instance with no error anywhere. This page
 * is the last chance to see that before it happens.
 */
#[Route(
    '/install/status',
    name: 'app_install_status',
    methods: ['GET'],
)]
final class ShowStatusController extends AppController
{
    public function __construct(
        private readonly InstallAccessGuard $installAccessGuard,
        private readonly CheckSystemStatusHandler $checkSystemStatus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->installAccessGuard->ensureAccessible($request);

        if (true !== $request->getSession()->get(SeedFlagsController::SESSION_FLAGS_SEEDED)) {
            return $this->redirectToRoute('app_install_flags');
        }

        return $this->render('@Account/install/show_status.html.twig', [
            'status' => ($this->checkSystemStatus)(),
        ]);
    }
}
