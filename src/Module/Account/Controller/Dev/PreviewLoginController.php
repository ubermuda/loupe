<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Command\PreviewLoginCommand;
use App\Module\Account\Command\PreviewLoginHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

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
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly PreviewLoginHandler $previewLogin,

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
            throw $this->createNotFoundException('This preview link does not verify here. It is signed for one host, so mint it inside the worktree you are opening.');
        }

        $email = $request->query->getString('email');

        if (null === ($this->previewLogin)(new PreviewLoginCommand($email))) {
            throw $this->createNotFoundException(sprintf('No account exists for %s. Seed it with app:dev:seed.', $email));
        }

        return $this->redirect($request->query->getString('to', '/'));
    }
}
