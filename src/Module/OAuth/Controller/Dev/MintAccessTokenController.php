<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\Dev\MintAccessTokenCommand;
use App\Module\OAuth\Command\Dev\MintAccessTokenHandler;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SECURITY: this hands an access token to whoever is signed in, with no
 * consent screen. #[When('dev')] is what keeps it out of production, not the
 * firewall, which ships everywhere.
 *
 * It exists for the Playwright suite, which would otherwise drive the device
 * flow for every test that calls an API.
 */
#[Route(
    '/dev/oauth/access-token',
    name: 'dev_oauth_mint_access_token',
    methods: ['POST'],
)]
#[Route(
    '/dev/oauth/access-token/{id:project}',
    name: 'dev_oauth_mint_project_access_token',
    methods: ['POST'],
)]
#[When('dev')]
final class MintAccessTokenController extends AppController
{
    public function __construct(
        private readonly MintAccessTokenHandler $mintAccessToken,
    ) {
    }

    public function __invoke(Request $request, ?Project $project = null): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(self::class.' needs a signed-in user; the ROLE_USER catch-all must cover it.');
        }

        $scopes = array_values(array_filter(explode(' ', $request->request->getString('scopes', 'agent'))));
        if ([] === $scopes) {
            return new JsonResponse(['error' => 'name at least one scope'], Response::HTTP_BAD_REQUEST);
        }

        if (null !== $project && $project->owner->id?->toRfc4122() !== $user->id?->toRfc4122()) {
            return new JsonResponse(['error' => 'that project is not yours'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['accessToken' => ($this->mintAccessToken)(new MintAccessTokenCommand($user, $scopes, $project))]);
    }
}
