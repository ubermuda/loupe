<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Command\SeedInstallFlagsHandler;
use App\Module\Account\Form\InstallFlagsFormType;
use App\Module\Account\Form\InstallFlagsRequest;
use App\Module\Account\Service\InstallAccessGuard;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/install',
    name: 'app_install_flags',
    methods: ['GET', 'POST'],
)]
final class SeedFlagsController extends AppController
{
    public const string SESSION_FLAGS_SEEDED = 'install_flags_seeded';

    public function __construct(
        private readonly InstallAccessGuard $installAccessGuard,
        private readonly SeedInstallFlagsHandler $seedInstallFlagsHandler,
        private readonly Auditor $auditor,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->installAccessGuard->ensureAccessible($request);

        $form = $this->createForm(InstallFlagsFormType::class, $data = new InstallFlagsRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->seedInstallFlagsHandler)(new SeedInstallFlagsCommand(
                registrationCap: $data->registrationCap ?? throw new \LogicException('registrationCap required after validation'),
                registrationEnabled: $data->registrationEnabled,
                billingEnabled: $data->billingEnabled,
                billingTrialDays: $data->billingTrialDays ?? throw new \LogicException('billingTrialDays required after validation'),
                billingStripePriceId: ('' === ($data->billingStripePriceId ?? '')) ? null : $data->billingStripePriceId,
                authGithubEnabled: $data->authGithubEnabled,
                authGoogleEnabled: $data->authGoogleEnabled,
            ));
            $request->getSession()->set(self::SESSION_FLAGS_SEEDED, true);
            $this->auditor->record('account.install.flags_seeded', AuditOutcome::Success);

            return $this->redirectToRoute('app_install_status');
        }

        return $this->renderFormResponse('@Account/install/seed_flags.html.twig', $form);
    }
}
