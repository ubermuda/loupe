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
 * SECURITY: this route grants a session from a URL. #[When('dev')] keeps it out
 * of production, and the signature covers the whole URL, host included, so a
 * link cannot be replayed against a sibling worktree.
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
            throw $this->createNotFoundException();
        }

        if (!$this->uriSigner->checkRequest($request)) {
            throw $this->createNotFoundException();
        }

        try {
            $user = $this->userProvider->loadUserByIdentifier($request->query->getString('email'));
        } catch (UserNotFoundException) {
            throw $this->createNotFoundException();
        }

        $this->security->login($user, 'form_login');

        return $this->redirect($request->query->getString('to', '/'));
    }
}
