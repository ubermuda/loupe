<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Wizard;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\MintProjectMcpTokenCommand;
use App\Module\Project\Command\MintProjectMcpTokenHandler;
use App\Module\Project\Service\WizardState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('wizard-mint-mcp')]
#[Route(
    '/welcome/connect/mcp-token',
    name: 'app_welcome_mint_mcp',
    methods: ['POST'],
)]
class MintWelcomeMcpTokenController extends AppController
{
    public function __construct(
        private readonly WizardState $wizardState,
        private readonly MintProjectMcpTokenHandler $mintProjectMcpTokenHandler,
        private readonly TranslatorInterface $translator,
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

        try {
            $raw = ($this->mintProjectMcpTokenHandler)(new MintProjectMcpTokenCommand($project));
            $this->addFlash('minted_mcp_token', $raw);
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_welcome_connect');
    }
}
