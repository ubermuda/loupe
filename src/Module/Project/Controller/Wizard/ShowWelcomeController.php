<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ShowWizardCommand;
use App\Module\Project\Command\ShowWizardHandler;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
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
        private readonly ShowWizardHandler $showWizard,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $wizard = ($this->showWizard)(new ShowWizardCommand($user));

        if ($wizard->completed) {
            return $this->redirectToRoute('app_home');
        }

        if (null !== $wizard->project) {
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
