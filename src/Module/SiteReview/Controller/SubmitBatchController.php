<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\SubmitBatchCommand;
use App\Module\SiteReview\Command\SubmitBatchHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/api/site-review/batches',
    name: 'app_site_review_submit',
    methods: ['POST'],
)]
final class SubmitBatchController extends AppController
{
    public function __construct(
        private readonly SubmitBatchHandler $handler,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user expected on the api firewall.');
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['comments']) || !is_array($payload['comments']) || [] === $payload['comments']) {
            return $this->json(['errors' => ['comments' => 'at least one comment is required']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $comments = [];
        foreach ($payload['comments'] as $i => $raw) {
            if (!is_array($raw)) {
                return $this->json(['errors' => ["comments.$i" => 'must be an object']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $body = is_string($raw['body'] ?? null) ? trim($raw['body']) : '';
            $selector = is_string($raw['selector'] ?? null) ? $raw['selector'] : '';
            $url = is_string($raw['url'] ?? null) ? $raw['url'] : '';
            $text = is_string($raw['text'] ?? null) ? $raw['text'] : '';
            if ('' === $body || '' === $selector || '' === $url) {
                return $this->json(['errors' => ["comments.$i" => 'body, selector and url are required']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $comments[] = ['body' => $body, 'selector' => $selector, 'text' => $text, 'url' => $url];
        }

        $batch = ($this->handler)(new SubmitBatchCommand($user, $comments));
        $batchId = $batch->id ?? throw new \LogicException('Batch id is set after flush.');

        return $this->json(['batchId' => (string) $batchId], JsonResponse::HTTP_CREATED);
    }
}
