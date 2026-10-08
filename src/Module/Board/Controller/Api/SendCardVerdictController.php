<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\SendCardVerdictCommand;
use App\Module\Board\Command\SendCardVerdictHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Records a verdict from the site-review widget. The firewall grants
 * `^/api/board/cards` to the widget token, so this path needs no line of its own.
 */
#[Route(
    '/api/board/cards/{cardId}/verdicts',
    name: 'api_board_card_verdict_send',
    requirements: ['cardId' => '[^/]+'],
    methods: ['POST'],
)]
final class SendCardVerdictController extends AppController
{
    private const array REFUSALS = [
        SendCardVerdictHandler::CARD_GONE => ['card_not_found', JsonResponse::HTTP_NOT_FOUND],
        SendCardVerdictHandler::CARD_CLOSED => ['card_closed', JsonResponse::HTTP_CONFLICT],
        SendCardVerdictHandler::MESSAGE_REQUIRED => ['message_required', JsonResponse::HTTP_UNPROCESSABLE_ENTITY],
        SendCardVerdictHandler::PULL_REQUEST_NOT_ON_CARD => ['pull_request_not_on_card', JsonResponse::HTTP_UNPROCESSABLE_ENTITY],
    ];

    public function __construct(
        private readonly SendCardVerdictHandler $handler,
        private readonly AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    public function __invoke(string $cardId, #[MapRequestPayload] SendCardVerdictRequest $payload): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            return $this->json(['error' => 'token_not_bound_to_site'], JsonResponse::HTTP_FORBIDDEN);
        }

        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('A verdict was sent without an authenticated User.');
        }

        try {
            $verdict = ($this->handler)(new SendCardVerdictCommand(
                project: $project,
                cardId: $cardId,
                reviewer: $reviewer,
                kind: $payload->kind ?? throw new \LogicException('kind required after validation'),
                pullRequestIds: $payload->pullRequestIds,
                message: $payload->message,
            ));
        } catch (DomainErrors $error) {
            [$code, $status] = self::REFUSALS[array_first($error->errors)] ?? ['verdict_refused', JsonResponse::HTTP_UNPROCESSABLE_ENTITY];

            return $this->json(['error' => $code], $status);
        }

        return $this->json([
            'verdictId' => (string) $verdict->id,
            'kind' => $verdict->kind->value,
            'noteCount' => \count($verdict->notes),
        ], JsonResponse::HTTP_CREATED);
    }
}
