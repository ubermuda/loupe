<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/welcome',
    name: 'app_welcome',
    methods: ['GET'],
)]
class ShowWelcomeController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        if (null !== $this->wizardState->firstProject($user)) {
            return $this->redirectToRoute('app_welcome_connect');
        }

        $form = $this->createForm(CreateProjectFormType::class, new CreateProjectRequest(), [
            'action' => $this->generateUrl('app_welcome_create_project'),
        ]);

        return $this->render('@Project/wizard/show_welcome.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
