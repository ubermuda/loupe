<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands an authenticated API client everything it needs to subscribe to its own
 * site-review event stream: the public hub URL, the per-user topic, and a
 * subscriber-scoped Mercure JWT. The bridge CLI calls this with its API token,
 * then opens an SSE connection to {hubUrl}?topic={topic} with the returned JWT.
 *
 * Authorization is role-only (ROLE_API_SITE_REVIEW, enforced by the firewall
 * access_control on ^/api/site-review): the caller can only ever obtain creds
 * for its own topic, so there is no entity subject to vote on.
 */
#[Route(
    '/api/site-review/stream',
    name: 'api_site_review_stream',
    methods: ['GET'],
)]
final class StreamCredentialsController extends AppController
{
    public function __construct(
        private readonly SiteReviewTopicBuilder $topicBuilder,

        #[Autowire(service: 'mercure.hub.default.jwt.factory')]
        private readonly TokenFactoryInterface $tokenFactory,

        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        private readonly string $hubUrl,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Stream endpoint reached without an authenticated User.');
        }

        $topic = $this->topicBuilder->forUser($user->id ?? throw new \LogicException('Authenticated user has no id.'));
        $jwt = $this->tokenFactory->create([$topic], []);

        return new JsonResponse([
            'hubUrl' => $this->hubUrl,
            'topic' => $topic,
            'jwt' => $jwt,
        ]);
    }
}
