<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Uuid $tokenId, Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $token = $this->apiTokens->find($tokenId);

        if (!$token instanceof ApiToken || null === $token->owner->id || !$token->owner->id->equals($user->id)) {
            throw $this->createNotFoundException('Token not found.');
        }

        $label = $token->label;
        $this->em->remove($token);
        $this->em->flush();

        $this->addFlash('success', sprintf('Token "%s" has been revoked.', $label));

        $this->logger->info('account.api_token.revoked', [
            'userId' => (string) $user->id,
            'tokenId' => (string) $tokenId,
            'label' => $label,
        ]);

        $returnTo = $request->request->get('returnTo');
        if (is_string($returnTo) && (str_starts_with($returnTo, '/projects/') || str_starts_with($returnTo, '/site-review/') || str_starts_with($returnTo, '/account/'))) {
            return $this->redirect($returnTo);
        }

        if (null !== $returnTo) {
            $this->logger->info('account.api_token.return_to_rejected', [
                'returnTo' => (string) $returnTo,
            ]);
        }

        return $this->redirectToRoute('app_api_tokens');
    }
}
