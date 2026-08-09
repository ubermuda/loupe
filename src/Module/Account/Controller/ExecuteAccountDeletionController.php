<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\DeleteAccountCommand;
use App\Module\Account\Command\DeleteAccountHandler;
use App\Routing\PaywallExempt;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('confirm-account-deletion')]
#[PaywallExempt]
#[Route(
    '/account/delete/confirm',
    name: 'app_account_delete_execute',
    methods: ['POST'],
)]
class ExecuteAccountDeletionController extends AppController
{
    public function __construct(
        private readonly DeleteAccountHandler $deleteAccount,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->request->get('token');

        try {
            ($this->deleteAccount)(new DeleteAccountCommand(is_string($token) ? $token : ''));
        } catch (DomainErrors) {
            $this->addFlash('error', $this->translator->trans('account.delete.error.invalid_token'));

            return $this->redirectToRoute('app_account_delete_confirm');
        }

        $response = $this->redirectToRoute('app_account_deleted');

        // The firewall's own logout, so the session dies and remember-me is
        // cleared under the configured name/path/domain. Guarded because the
        // confirm link can be opened logged-out, and logout() throws when there
        // is no authenticated token.
        if (null !== $this->security->getToken()?->getUser()) {
            $logoutResponse = $this->security->logout(validateCsrfToken: false);
            // logout() returns the firewall's own response (its target URL is
            // discarded in favor of the account-deleted page), but the clearing
            // Set-Cookie headers it wrote — session and remember-me — must
            // still land on the response we actually send.
            foreach ($logoutResponse?->headers->getCookies() ?? [] as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        return $response;
    }
}
