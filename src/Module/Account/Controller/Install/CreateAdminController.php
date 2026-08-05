<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Install;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateInstallAdminCommand;
use App\Module\Account\Command\CreateInstallAdminHandler;
use App\Module\Account\Form\InstallAdminFormType;
use App\Module\Account\Form\InstallAdminRequest;
use App\Module\Account\Service\InstallAccessGuard;
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
        private readonly InstallAccessGuard $installAccessGuard,
        private readonly CreateInstallAdminHandler $createInstallAdminHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->installAccessGuard->ensureAccessible($request);

        if (true !== $request->getSession()->get(SeedFlagsController::SESSION_FLAGS_SEEDED)) {
            return $this->redirectToRoute('app_install_flags');
        }

        $form = $this->createForm(InstallAdminFormType::class, $data = new InstallAdminRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Not the `?:` idiom used for the other fields: "0" is a legitimate
            // display name that NotBlank accepts and a truthiness check would
            // reject.
            $fullName = $data->fullName;
            if (null === $fullName || '' === $fullName) {
                throw new \LogicException('fullName required after validation');
            }

            try {
                ($this->createInstallAdminHandler)(new CreateInstallAdminCommand(
                    email: $data->email ?: throw new \LogicException('email required after validation'),
                    fullName: $fullName,
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
