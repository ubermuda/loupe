<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\AddCommentCommand;
use App\Module\Review\Command\AddCommentHandler;
use App\Module\Review\Command\ListLatestCommentsCommand;
use App\Module\Review\Command\ListLatestCommentsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/comments',
    name: 'app_comment_add',
    methods: ['POST'],
)]
final class AddCommentController extends AppController
{
    public function __construct(
        private readonly AddCommentHandler $addCommentHandler,
        private readonly ListLatestCommentsHandler $listLatestComments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        Request $request,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $data = new AddCommentRequest();
        $form = $this->createForm(AddCommentFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $errorMessage = $this->formErrorMessage($form);
        } else {
            try {
                ($this->addCommentHandler)(new AddCommentCommand(
                    actor: $user,
                    document: $document,
                    quote: $data->quote,
                    prefix: $data->prefix,
                    suffix: $data->suffix,
                    body: $data->body ?: throw new \LogicException('body required after validation'),
                ));
                $errorMessage = null;
            } catch (DomainErrors $e) {
                $errorMessage = implode(' ', array_map(
                    fn (string $key): string => $this->translator->trans($key),
                    $e->errors,
                ));
            }
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        if (null !== $errorMessage) {
            return new Response(
                $this->renderView('@Review/_composer_error.stream.html.twig', [
                    'target' => 'composer-error',
                    'message' => $errorMessage,
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        return new Response(
            $this->renderView('@Review/_comment_added.stream.html.twig', [
                'comments' => ($this->listLatestComments)(new ListLatestCommentsCommand($document))->comments,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }

    /**
     * @param FormInterface<AddCommentRequest> $form
     */
    private function formErrorMessage(FormInterface $form): string
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = $error->getMessage();
        }

        return implode(' ', $messages) ?: $this->translator->trans('review.document.comment.add_failed');
    }
}
