<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/welcome/project',
    name: 'app_welcome_create_project',
    methods: ['POST'],
)]
class CreateFirstProjectController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly CreateProjectHandler $createProjectHandler,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        if (null !== $this->wizardState->firstProject($user)) {
            return $this->redirectToRoute('app_welcome_connect');
        }

        $data = new CreateProjectRequest();
        $form = $this->createForm(CreateProjectFormType::class, $data, [
            'action' => $this->generateUrl('app_welcome_create_project'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->createProjectHandler)(new CreateProjectCommand(
                    owner: $user,
                    name: trim($data->name ?? '') ?: throw new \LogicException('name required after validation'),
                    domain: trim($data->domain ?? '') ?: null,
                ));

                return $this->redirectToRoute('app_welcome_connect');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Project/wizard/show_welcome.html.twig', $form);
    }
}
