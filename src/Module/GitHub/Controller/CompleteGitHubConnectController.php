<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Command\CompleteGitHubConnectCommand;
use App\Module\GitHub\Command\CompleteGitHubConnectHandler;
use App\Module\GitHub\Service\GitHubConnectSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The callback URL of the App for a person's own account. It stores the
 * connection for the signed-in person, so no voter is needed. It runs in the
 * popup of the widget or in a normal tab, and it cannot tell which.
 */
#[Route(
    '/account/github/callback',
    name: 'app_github_user_callback',
    methods: ['GET'],
)]
final class CompleteGitHubConnectController extends AppController
{
    public const string MESSAGE_TYPE = 'loupe-github-connect';

    public function __construct(
        private readonly GitHubConnectSession $connectSession,
        private readonly CompleteGitHubConnectHandler $completeConnect,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $connectedApps = $this->redirectToRoute('app_account_connected_apps');
        $pending = $this->connectSession->take();
        if (null === $pending) {
            $this->addFlash('error', $this->translator->trans('github.connect.flash.expired'));

            return $connectedApps;
        }

        try {
            $connected = ($this->completeConnect)(new CompleteGitHubConnectCommand(
                $user,
                $pending,
                $request->query->has('state') ? $request->query->getString('state') : null,
                $request->query->has('code') ? $request->query->getString('code') : null,
                $this->generateUrl('app_github_user_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $connectedApps;
        }

        $response = $this->render('@GitHub/complete_github_connect.html.twig', [
            'connected' => $connected,
            'message' => ['type' => self::MESSAGE_TYPE, 'connected' => true],
            'connectedAppsUrl' => $this->generateUrl('app_account_connected_apps'),
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
