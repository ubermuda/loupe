<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/welcome/done',
    name: 'app_welcome_done',
    methods: ['GET'],
)]
class ShowWelcomeDoneController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        $project = $this->wizardState->firstProject($user);
        if (null === $project) {
            return $this->redirectToRoute('app_welcome');
        }

        // See ShowWelcomeConnectController for why this key isn't `project`:
        // base.html.twig's own top-level `project` (from current_project(),
        // route-param resolved) would clobber it on the param-less /welcome/* routes.
        return $this->render('@Project/wizard/show_welcome_done.html.twig', [
            'wizardProject' => $project,
        ]);
    }
}
