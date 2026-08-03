<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\ArchiveDocumentFormType;
use App\Module\Review\Form\ReplyFormType;
use App\Module\Review\Form\ReplyRequest;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Builds the per-thread reply form. A thread renders one reply form each, so the
 * form is created here (per the "form per item in a loop" convention) rather than
 * an array of forms in the controller. Each form is named after its comment so the
 * rendered field ids/names don't collide across threads.
 */
final class ReviewExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('comment_reply_form', $this->commentReplyForm(...)),
            new TwigFunction('document_archive_form', $this->documentArchiveForm(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('relative_time', $this->relativeTime(...)),
        ];
    }

    /**
     * A compact, human relative time ("2h ago", "3d ago", "last week", "just now")
     * for the documents list meta line. English-only — the app currently ships a
     * single locale, so a full locale-aware relative formatter would be premature.
     */
    public function relativeTime(\DateTimeImmutable $when, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $seconds = max(0, $now->getTimestamp() - $when->getTimestamp());

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => intdiv($seconds, 60).'m ago',
            $seconds < 86400 => intdiv($seconds, 3600).'h ago',
            $seconds < 604800 => intdiv($seconds, 86400).'d ago',
            $seconds < 1209600 => 'last week',
            $seconds < 2592000 => intdiv($seconds, 604800).'w ago',
            $seconds < 5259600 => 'last month',
            $seconds < 31557600 => intdiv($seconds, 2592000).'mo ago',
            default => intdiv($seconds, 31557600).'y ago',
        };
    }

    public function commentReplyForm(Comment $comment): FormView
    {
        return $this->formFactory->createNamed(
            self::replyFormName($comment),
            ReplyFormType::class,
            new ReplyRequest(),
            [
                'action' => $this->urlGenerator->generate('app_comment_reply', ['id' => (string) $comment->id]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /**
     * Stable form name shared by the rendered form (here) and the controller that
     * binds the submission, so handleRequest() reads the right request key.
     */
    public static function replyFormName(Comment $comment): string
    {
        return 'reply_'.$comment->id?->toBase32();
    }

    /**
     * The archive control for one row of the documents list, pointed at whichever
     * of the two routes the document's current state calls for.
     *
     * The list state rides on the action URL so the redirect afterwards returns
     * to the page and filter the reader submitted from.
     *
     * @param array<string, int|string> $listParams
     */
    public function documentArchiveForm(Document $document, array $listParams = []): FormView
    {
        $route = null === $document->archivedAt ? 'app_document_archive' : 'app_document_unarchive';

        return $this->formFactory->createNamed(
            self::archiveFormName($document),
            ArchiveDocumentFormType::class,
            null,
            [
                'action' => $this->urlGenerator->generate($route, [
                    'projectId' => (string) $document->project->id,
                    'documentId' => (string) $document->id,
                    ...$listParams,
                ]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /**
     * One name per document rather than one for the whole list: the rendered
     * token input would otherwise repeat the same DOM id on every row.
     */
    public static function archiveFormName(Document $document): string
    {
        return 'archive_document_'.$document->id?->toBase32();
    }
}
