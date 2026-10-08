<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Command\DisconnectGitHubCommand;
use App\Module\GitHub\Command\DisconnectGitHubHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** The handler touches only the signed-in user's own connection, so no voter is needed. */
#[CsrfToken('github-account-disconnect')]
#[Route(
    '/account/github/disconnect',
    name: 'app_github_user_disconnect',
    methods: ['POST'],
)]
final class DisconnectGitHubController extends AppController
{
    public function __construct(
        private readonly DisconnectGitHubHandler $disconnect,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $revoked = ($this->disconnect)(new DisconnectGitHubCommand($user));
        $this->addFlash($revoked ? 'success' : 'warning', $this->translator->trans($revoked ? 'github.connect.flash.disconnected' : 'github.connect.flash.disconnected_not_revoked'));

        return $this->redirectToRoute('app_account_connected_apps');
    }
}
