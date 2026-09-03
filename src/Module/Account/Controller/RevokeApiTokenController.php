<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\RevokeApiTokenCommand;
use App\Module\Account\Command\RevokeApiTokenHandler;
use App\Module\Account\Command\ShowOwnedApiTokenCommand;
use App\Module\Account\Command\ShowOwnedApiTokenHandler;
use App\Module\Account\Entity\User;
use App\Utils\SafeRedirect;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('revoke-api-token')]
#[Route(
    '/account/api-tokens/{tokenId}/revoke',
    name: 'app_api_token_revoke',
    methods: ['POST'],
)]
#[WithMonologChannel('app_security')]
class RevokeApiTokenController extends AppController
{
    public function __construct(
        private readonly ShowOwnedApiTokenHandler $showOwnedApiToken,
        private readonly RevokeApiTokenHandler $revokeApiTokenHandler,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Uuid $tokenId, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $token = ($this->showOwnedApiToken)(new ShowOwnedApiTokenCommand($tokenId, $user))->token;

        if (null === $token) {
            throw $this->createNotFoundException('Token not found.');
        }

        $label = $token->label;
        ($this->revokeApiTokenHandler)(new RevokeApiTokenCommand($token));

        $this->addFlash('success', $this->translator->trans('account.api_token.flash.revoked', ['%label%' => $label]));

        // returnTo must be a same-origin local path and inside /projects/ — both
        // checks apply to different attack shapes (protocol-relative/backslash-host
        // targets vs. an off-site same-slash-prefixed path).
        $returnTo = $request->request->get('returnTo');
        if (is_string($returnTo) && SafeRedirect::isLocalPath($returnTo) && str_starts_with($returnTo, '/projects/')) {
            return $this->redirect($returnTo);
        }

        if (null !== $returnTo) {
            $this->logger->info('account.api_token_return_to_rejected', [
                'returnTo' => (string) $returnTo,
            ]);
        }

        return $this->redirectToRoute('app_projects');
    }
}
