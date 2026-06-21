<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\SubmitBatchCommand;
use App\Module\SiteReview\Command\SubmitBatchHandler;
use App\Module\SiteReview\Dto\SiteReviewCommentRequest;
use App\Module\SiteReview\Dto\SubmitBatchRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/site-review/batches',
    name: 'api_site_review_submit',
    methods: ['POST'],
)]
final class SubmitBatchController extends AppController
{
    public function __construct(
        private readonly SubmitBatchHandler $handler,
    ) {
    }

    public function __invoke(#[MapRequestPayload] SubmitBatchRequest $payload): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user expected on the api firewall.');
        }

        $comments = array_map(
            static fn (SiteReviewCommentRequest $comment): array => [
                'body' => trim($comment->body ?? ''),
                'selector' => $comment->selector,
                'text' => $comment->text,
                'url' => $comment->url ?? '',
            ],
            $payload->comments,
        );

        $batch = ($this->handler)(new SubmitBatchCommand($user, $comments));
        $batchId = $batch->id ?? throw new \LogicException('Batch id is set after flush.');

        return $this->json(['batchId' => (string) $batchId], JsonResponse::HTTP_CREATED);
    }
}
