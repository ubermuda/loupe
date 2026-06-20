<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\SubmitBatchCommand;
use App\Module\SiteReview\Command\SubmitBatchHandler;
use App\Module\SiteReview\Form\SiteReviewCommentRequest;
use App\Module\SiteReview\Form\SubmitBatchFormType;
use App\Module\SiteReview\Form\SubmitBatchRequest;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user expected on the api firewall.');
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['errors' => ['body' => 'a JSON object is required']], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $form = $this->createForm(SubmitBatchFormType::class, new SubmitBatchRequest());
        $form->submit($payload);

        if (!$form->isValid()) {
            return $this->json(['errors' => $this->collectErrors($form)], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dto = $form->getData();
        $comments = array_map(
            static fn (SiteReviewCommentRequest $comment): array => [
                'body' => $comment->body ?: throw new \LogicException('body required after validation'),
                'selector' => $comment->selector ?? '',
                'text' => $comment->text ?? '',
                'url' => $comment->url ?: throw new \LogicException('url required after validation'),
            ],
            $dto->comments,
        );

        $batch = ($this->handler)(new SubmitBatchCommand($user, $comments));
        $batchId = $batch->id ?? throw new \LogicException('Batch id is set after flush.');

        return $this->json(['batchId' => (string) $batchId], JsonResponse::HTTP_CREATED);
    }

    /**
     * @param FormInterface<SubmitBatchRequest> $form
     *
     * @return array<string, string>
     */
    private function collectErrors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            if (!$error instanceof FormError) {
                continue;
            }
            $origin = $error->getOrigin();
            $field = null !== $origin ? $origin->getName() : 'form';
            $errors[$field] = $error->getMessage();
        }

        return $errors;
    }
}
