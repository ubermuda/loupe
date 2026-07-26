<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateInstallAdminCommand;
use App\Module\Account\Command\CreateInstallAdminHandler;
use App\Module\Account\Form\InstallAdminFormType;
use App\Module\Account\Form\InstallAdminRequest;
use App\Module\Account\Service\InstallationState;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/install/admin',
    name: 'app_install_admin',
    methods: ['GET', 'POST'],
)]
final class CreateAdminController extends AppController
{
    public function __construct(
        private readonly InstallationState $installationState,
        private readonly CreateInstallAdminHandler $createInstallAdminHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->installationState->isOpen()) {
            throw $this->createNotFoundException();
        }
        if (true !== $request->getSession()->get(SeedFlagsController::SESSION_FLAGS_SEEDED)) {
            return $this->redirectToRoute('app_install_flags');
        }

        $form = $this->createForm(InstallAdminFormType::class, $data = new InstallAdminRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->createInstallAdminHandler)(new CreateInstallAdminCommand(
                    username: $data->username ?: throw new \LogicException('username required after validation'),
                    fullName: $data->fullName ?: throw new \LogicException('fullName required after validation'),
                    email: $data->email ?: throw new \LogicException('email required after validation'),
                    plainPassword: $data->plainPassword ?: throw new \LogicException('plainPassword required after validation'),
                ));
                $this->logger->info('account.install.admin_created', []);
                $request->getSession()->remove(SeedFlagsController::SESSION_FLAGS_SEEDED);
                $request->getSession()->set(ShowDoneController::SESSION_INSTALL_COMPLETED, true);

                return $this->redirectToRoute('app_install_done');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Account/install/create_admin.html.twig', $form);
    }
}
