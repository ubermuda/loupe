<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\RevokeApiTokenCommand;
use App\Module\Account\Command\RevokeApiTokenHandler;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Utils\SafeRedirect;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('revoke-api-token')]
#[Route(
    '/account/api-tokens/{tokenId}/revoke',
    name: 'app_api_token_revoke',
    methods: ['POST'],
)]
class RevokeApiTokenController extends AppController
{
    public function __construct(
        private readonly ApiTokenRepository $apiTokens,
        private readonly RevokeApiTokenHandler $revokeApiTokenHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Uuid $tokenId, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        $token = $this->apiTokens->find($tokenId);

        if (!$token instanceof ApiToken || null === $token->owner->id || !$token->owner->id->equals($user->id)) {
            throw $this->createNotFoundException('Token not found.');
        }

        $label = $token->label;
        ($this->revokeApiTokenHandler)(new RevokeApiTokenCommand($token));

        $this->addFlash('success', sprintf('Token "%s" has been revoked.', $label));

        // returnTo must be a same-origin local path and inside /projects/ — both
        // checks apply to different attack shapes (protocol-relative/backslash-host
        // targets vs. an off-site same-slash-prefixed path).
        $returnTo = $request->request->get('returnTo');
        if (is_string($returnTo) && SafeRedirect::isLocalPath($returnTo) && str_starts_with($returnTo, '/projects/')) {
            return $this->redirect($returnTo);
        }

        if (null !== $returnTo) {
            $this->logger->info('account.api_token.return_to_rejected', [
                'returnTo' => (string) $returnTo,
            ]);
        }

        return $this->redirectToRoute('app_projects');
    }
}
