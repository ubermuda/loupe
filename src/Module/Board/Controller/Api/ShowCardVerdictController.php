<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowCardVerdictCommand;
use App\Module\Board\Command\ShowCardVerdictHandler;
use App\Module\Board\Command\ShowCardVerdictView;
use App\Module\Board\Command\VerdictPullRequestOption;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Service\PullRequestLabel;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the widget shows in its verdict panel for one card. The firewall grants
 * `^/api/board/cards` to the widget token, so this path needs no line of its own.
 */
#[Route(
    '/api/board/cards/{cardId}/verdict',
    name: 'api_board_card_verdict_show',
    requirements: ['cardId' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowCardVerdictController extends AppController
{
    public function __construct(
        private readonly ShowCardVerdictHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $cardId): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('The verdict panel was reached without an authenticated User.');
        }

        $view = ($this->handler)(new ShowCardVerdictCommand($project, $cardId, $reviewer));
        if (!$view instanceof ShowCardVerdictView) {
            return $this->json(['error' => 'card_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $latest = $view->latest;

        return $this->json([
            'cardId' => (string) $view->card->id,
            'connection' => ['state' => $view->connection],
            'pullRequests' => array_map(
                static fn (VerdictPullRequestOption $option): array => [
                    'id' => (string) $option->id,
                    'label' => $option->label,
                    'ownPullRequest' => $option->ownPullRequest,
                ],
                $view->pullRequests,
            ),
            'notes' => $view->notes,
            'preview' => [],
            'latestVerdict' => null === $latest ? null : [
                'id' => (string) $latest->id,
                'kind' => $latest->kind->value,
                'message' => $latest->message,
                'createdAt' => $latest->createdAt->format(\DATE_ATOM),
                'deliveries' => array_map(
                    static fn (CardVerdictDelivery $delivery): array => [
                        'pullRequestId' => (string) $delivery->pullRequest->id,
                        'label' => PullRequestLabel::of($delivery->pullRequest),
                        'state' => $delivery->state->value,
                        'reason' => $delivery->reason,
                        'reviewUrl' => $delivery->reviewUrl,
                    ],
                    $view->latestDeliveries,
                ),
            ],
        ]);
    }
}
