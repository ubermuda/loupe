<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\MintProjectWidgetTokenCommand;
use App\Module\Project\Command\MintProjectWidgetTokenHandler;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('wizard-mint-widget')]
#[Route(
    '/welcome/widget/token',
    name: 'app_welcome_mint_widget',
    methods: ['POST'],
)]
class MintWelcomeWidgetTokenController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly MintProjectWidgetTokenHandler $mintProjectWidgetTokenHandler,
        private readonly TranslatorInterface $translator,
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

        try {
            $raw = ($this->mintProjectWidgetTokenHandler)(new MintProjectWidgetTokenCommand($project));
            $this->addFlash('minted_widget_token', $raw);
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_welcome_widget');
    }
}
