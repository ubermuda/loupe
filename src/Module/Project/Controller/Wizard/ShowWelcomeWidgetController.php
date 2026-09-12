<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ShowWizardCommand;
use App\Module\Project\Command\ShowWizardHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/welcome/widget',
    name: 'app_welcome_widget',
    methods: ['GET'],
)]
class ShowWelcomeWidgetController extends AppController
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

        if (null === $wizard->project) {
            return $this->redirectToRoute('app_welcome');
        }

        // base.html.twig sets its own top-level `project` variable from
        // current_project() (route-param resolved) for the sidebar nav — since
        // /welcome/* routes carry no {id} param that call resolves to null and
        // would silently clobber a same-named variable, so this uses a
        // differently-named key instead.
        return $this->render('@Project/wizard/show_welcome_widget.html.twig', [
            'wizardProject' => $wizard->project,
        ]);
    }
}
