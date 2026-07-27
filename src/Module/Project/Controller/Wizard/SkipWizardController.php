<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Module\Account\Command\CompleteWizardCommand;
use App\Module\Account\Command\CompleteWizardHandler;
use App\Module\Account\Entity\User;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('wizard-skip')]
#[Route(
    '/welcome/skip',
    name: 'app_welcome_skip',
    methods: ['POST'],
)]
class SkipWizardController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly CompleteWizardHandler $completeWizardHandler,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        // Skip must work from every step — including step 1, before a project
        // exists — so this checks only the completed guard, never the
        // project-required guard the other wizard endpoints use.
        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        ($this->completeWizardHandler)(new CompleteWizardCommand($user));

        return $this->redirectToRoute('app_projects');
    }
}
