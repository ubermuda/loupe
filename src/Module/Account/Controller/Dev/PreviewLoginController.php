<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * SECURITY: this grants a session from a URL. #[When('dev')] is what keeps it
 * from production, not the access_control rule, which ships everywhere. The
 * signature covers the whole URL, so a link cannot be replayed onto a sibling.
 */
#[Route(
    '/dev/preview-login',
    name: 'dev_preview_login',
    methods: ['GET'],
)]
#[When('dev')]
final class PreviewLoginController extends AppController
{
    /** @param UserProviderInterface<User> $userProvider */
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UserProviderInterface $userProvider,
        private readonly Security $security,

        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw $this->createNotFoundException('The preview-login route exists in dev only.');
        }

        if (!$this->uriSigner->checkRequest($request)) {
            // Unverified, so it decides the wording only and never access.
            $expiration = $request->query->getInt('_expiration');

            throw $this->createNotFoundException(0 !== $expiration && $expiration < time() ? 'This preview link has expired. Mint another one with app:dev:preview-login-link.' : 'This preview link does not verify here. It is signed for one host, so mint it inside the worktree you are opening.');
        }

        $email = $request->query->getString('email');

        try {
            $user = $this->userProvider->loadUserByIdentifier($email);
        } catch (UserNotFoundException) {
            throw $this->createNotFoundException(sprintf('No account exists for %s. Seed it with app:dev:seed.', $email));
        }

        $this->security->login($user, 'form_login');

        return $this->redirect($request->query->getString('to', '/'));
    }
}
