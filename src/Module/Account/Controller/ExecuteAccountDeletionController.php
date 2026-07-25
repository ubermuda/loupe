<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\DeleteAccountCommand;
use App\Module\Account\Command\DeleteAccountHandler;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('confirm-account-deletion')]
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

        $response = $this->redirectToRoute('app_account_goodbye');

        // Tear down the authenticated context through the firewall's own
        // logout machinery — invalidates the session and clears the
        // remember-me cookie under the CONFIGURED name/path/domain, and
        // dispatches LogoutEvent so every registered logout handler runs.
        // The confirm link can also be opened logged-out (the user followed it
        // from a fresh browser); logout() throws without an authenticated
        // token, so it only runs when there is one to tear down.
        if (null !== $this->security->getToken()?->getUser()) {
            $logoutResponse = $this->security->logout(validateCsrfToken: false);
            // logout() returns the firewall's own response (its target URL is
            // discarded in favor of the goodbye page), but the clearing
            // Set-Cookie headers it wrote — session and remember-me — must
            // still land on the response we actually send.
            foreach ($logoutResponse?->headers->getCookies() ?? [] as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        return $response;
    }
}
