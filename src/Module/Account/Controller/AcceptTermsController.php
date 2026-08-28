<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\AcceptTermsCommand;
use App\Module\Account\Command\AcceptTermsHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\RequireTermsAcceptanceListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

// POST-only, and separate from ShowAcceptTermsController, because
// ValidateCsrfTokenListener checks #[CsrfToken] on every method the controller
// answers — a GET sharing this class would 403 before it could render the form.
#[CsrfToken('accept-terms')]
#[Route(
    '/account/accept-terms',
    name: 'app_account_accept_terms_submit',
    methods: ['POST'],
)]
class AcceptTermsController extends AppController
{
    public function __construct(
        private readonly AcceptTermsHandler $acceptTerms,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        ($this->acceptTerms)(new AcceptTermsCommand($user));

        $intended = $request->getSession()->remove(RequireTermsAcceptanceListener::INTENDED_PATH_SESSION_KEY);

        // A path beginning `//` is protocol-relative, so redirecting to one
        // leaves the site — reject it even though we are the ones who stored it.
        if (!is_string($intended) || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirect($intended);
    }
}
