<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\RevokeConnectedAppCommand;
use App\Module\OAuth\Command\RevokeConnectedAppHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler touches only the signed-in user's own tokens, so no voter is needed. */
#[CsrfToken('revoke-connected-app')]
#[Route(
    '/account/connected-apps/revoke/{clientId}',
    name: 'app_account_connected_app_revoke',
    requirements: ['clientId' => '.+'],
    methods: ['POST'],
)]
final class RevokeConnectedAppController extends AppController
{
    public function __construct(
        private readonly RevokeConnectedAppHandler $revokeConnectedApp,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(string $clientId): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        ($this->revokeConnectedApp)(new RevokeConnectedAppCommand($user, $clientId));
        $this->addFlash('success', $this->translator->trans('oauth.connected_apps.flash.revoked'));

        return $this->redirectToRoute('app_account_connected_apps');
    }
}
