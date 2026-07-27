<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Service\WizardState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/welcome/connect',
    name: 'app_welcome_connect',
    methods: ['GET'],
)]
class ShowWelcomeConnectController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,

        #[Autowire(param: 'app.mcp.server_name')]
        private readonly string $mcpServerName,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        $project = $this->wizardState->firstProject($user);
        if (null === $project) {
            return $this->redirectToRoute('app_welcome');
        }

        // base.html.twig sets its own top-level `project` variable from
        // current_project() (route-param resolved) for the sidebar nav — since
        // /welcome/* routes carry no {id} param that call resolves to null and
        // would silently clobber a same-named variable, so this uses a
        // differently-named key instead.
        return $this->render('@Project/wizard/show_welcome_connect.html.twig', [
            'wizardProject' => $project,
            'mcpServerName' => $this->mcpServerName,
        ]);
    }
}
