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

#[CsrfToken('wizard-finish')]
#[Route('/welcome/done/finish', name: 'app_welcome_finish', methods: ['POST'])]
class FinishWizardController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly CompleteWizardHandler $completeWizardHandler,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        // Same shape as SkipWizardController: only the completed guard
        // applies, so a repeat submit is a no-op redirect rather than an error.
        if ($this->wizardState->isCompleted($user)) {
            return $this->redirectToRoute('app_home');
        }

        ($this->completeWizardHandler)(new CompleteWizardCommand($user));

        return $this->redirectToRoute('app_home');
    }
}
