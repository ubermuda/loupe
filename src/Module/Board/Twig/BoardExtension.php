<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Form\AddBoardColumnFormType;
use App\Module\Board\Form\AddBoardColumnRequest;
use App\Module\Board\Form\AttachSiteReviewCommentFormType;
use App\Module\Board\Form\AttachSiteReviewCommentRequest;
use App\Module\Board\Form\ConfigureBoardColumnFormType;
use App\Module\Board\Form\ConfigureBoardColumnRequest;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\DeleteBoardColumnRequest;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Form\MoveCardRequest;
use App\Module\Board\Form\RenameBoardColumnFormType;
use App\Module\Board\Form\RenameBoardColumnRequest;
use App\Module\Board\Form\ReorderBoardColumnsFormType;
use App\Module\Board\Form\ReorderBoardColumnsRequest;
use App\Module\Board\Form\SetDefaultBoardColumnFormType;
use App\Module\Board\Form\SetDefaultBoardColumnRequest;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardColumnTonePicker;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\View\DocumentListItem;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The board renders one move form per card, so the forms are built here rather
 * than passed down as an array from the controller. Each one carries the card's
 * own name, so the rendered field ids and names do not collide across cards.
 */
final class BoardExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly MarkdownRenderer $markdown,
        private readonly TranslatorInterface $translator,
        private readonly CardSiteReviewCommentRepository $cardSiteReviewComments,
        private readonly CardRepository $cards,
        private readonly CardDocumentRepository $cardDocuments,
        private readonly BoardColumnRepository $boardColumns,
        private readonly BoardColumnTonePicker $tonePicker,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_move_form', $this->cardMoveForm(...)),
            new TwigFunction('card_digest', $this->cardDigest(...)),
            new TwigFunction('board_column_add_form', $this->boardColumnAddForm(...)),
            new TwigFunction('board_column_rename_form', $this->boardColumnRenameForm(...)),
            new TwigFunction('board_column_configure_form', $this->boardColumnConfigureForm(...)),
            new TwigFunction('board_column_delete_form', $this->boardColumnDeleteForm(...)),
            new TwigFunction('board_column_default_form', $this->boardColumnDefaultForm(...)),
            new TwigFunction('board_columns_reorder_form', $this->boardColumnsReorderForm(...)),
            new TwigFunction('board_column_order', $this->boardColumnOrder(...)),
            new TwigFunction('safe_pull_request_url', $this->safePullRequestUrl(...)),
            new TwigFunction('card_site_review_links', $this->cardSiteReviewLinks(...)),
            new TwigFunction('site_review_attach_form', $this->siteReviewAttachForm(...)),
            new TwigFunction('document_card_links', $this->documentCardLinks(...)),
            new TwigFunction('document_card_link_map', $this->documentCardLinkMap(...)),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('card_body', $this->cardBody(...), ['is_safe' => ['html']]),
        ];
    }

    public function cardMoveForm(Card $card): FormView
    {
        return $this->formFactory
            ->createNamed(
                MoveCardFormType::nameFor($card),
                MoveCardFormType::class,
                new MoveCardRequest($card->column),
                ['project' => $card->project],
            )
            ->createView();
    }

    /**
     * A short hash of what the card face and its list row show, and of where the
     * card sits, so a page can tell a changed card from an unchanged one.
     */
    public function cardDigest(Card $card, int $pendingComments): string
    {
        return substr(sha1(json_encode([
            $card->number,
            $card->title,
            $card->body,
            $card->type->value,
            $pendingComments,
            $card->pullRequests->count(),
            $card->documents->count(),
            (string) $card->column->id,
            $card->position,
        ], \JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** @return array<string, CardSiteReviewComment> */
    public function cardSiteReviewLinks(Project $project): array
    {
        $links = [];
        foreach ($this->cardSiteReviewComments->findForProject($project) as $link) {
            $links[(string) $link->comment->id] = $link;
        }

        return $links;
    }

    public function siteReviewAttachForm(SiteReviewComment $comment): FormView
    {
        return $this->formFactory
            ->createNamed(
                AttachSiteReviewCommentFormType::nameFor($comment),
                AttachSiteReviewCommentFormType::class,
                new AttachSiteReviewCommentRequest(),
                ['cards' => $this->cards->searchOpenForProject($comment->project, '', 100)],
            )
            ->createView();
    }

    /** @return list<CardDocument> */
    public function documentCardLinks(Document $document): array
    {
        return $this->cardDocuments->findForDocument($document);
    }

    /**
     * @param list<DocumentListItem> $items
     *
     * @return array<string, list<CardDocument>>
     */
    public function documentCardLinkMap(array $items): array
    {
        $linksByDocument = [];
        foreach ($this->cardDocuments->findForDocuments(array_map(
            static fn (DocumentListItem $item): Document => $item->document,
            $items,
        )) as $link) {
            $linksByDocument[(string) $link->document->id][] = $link;
        }

        return $linksByDocument;
    }

    /**
     * The refused form a failed add forwarded, or a fresh one whose colour is
     * picked now, so the person sees it and can change it before saving.
     */
    public function boardColumnAddForm(Project $project, ?FormView $refused = null): FormView
    {
        return $refused ?? $this->formFactory->create(AddBoardColumnFormType::class, new AddBoardColumnRequest(
            tone: $this->tonePicker->pick($this->boardColumns->findForProject($project)),
        ))->createView();
    }

    /**
     * The refused form a failed rename forwarded, when it belongs to this
     * column. A fresh form shows the label as the board shows it, so a seeded
     * column offers its translated name rather than its translation key.
     */
    public function boardColumnRenameForm(BoardColumn $column, ?FormView $refused = null): FormView
    {
        $name = RenameBoardColumnFormType::nameFor($column);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory
            ->createNamed($name, RenameBoardColumnFormType::class, new RenameBoardColumnRequest($this->translator->trans($column->label), $column->label))
            ->createView();
    }

    /**
     * The refused form a failed configure forwarded, when it belongs to this
     * column, or a fresh one that shows the column as it is now.
     */
    public function boardColumnConfigureForm(BoardColumn $column, string $expectedDefaultId, ?FormView $refused = null): FormView
    {
        $name = ConfigureBoardColumnFormType::nameFor($column);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory
            ->createNamed($name, ConfigureBoardColumnFormType::class, new ConfigureBoardColumnRequest(
                label: $this->translator->trans($column->label),
                expectedLabel: $column->label,
                isDefault: $column->isDefault,
                terminal: $column->terminal,
                expectedDefaultId: $expectedDefaultId,
                expectedTerminal: $column->terminal ? '1' : '0',
                tone: $column->tone,
            ))
            ->createView();
    }

    /**
     * The target choices come from the board already rendered, so a board of
     * many columns runs no choice query per column.
     *
     * @param list<BoardColumnView> $columns
     */
    public function boardColumnDeleteForm(BoardColumn $column, array $columns): FormView
    {
        return $this->formFactory
            ->createNamed(DeleteBoardColumnFormType::nameFor($column), DeleteBoardColumnFormType::class, new DeleteBoardColumnRequest(), [
                'column' => $column,
                'columns' => array_map(static fn (BoardColumnView $view): BoardColumn => $view->column, $columns),
            ])
            ->createView();
    }

    public function boardColumnDefaultForm(BoardColumn $column, string $expectedDefaultId): FormView
    {
        return $this->formFactory
            ->createNamed(SetDefaultBoardColumnFormType::nameFor($column), SetDefaultBoardColumnFormType::class, new SetDefaultBoardColumnRequest($expectedDefaultId))
            ->createView();
    }

    public function boardColumnsReorderForm(string $order, string $expectedOrder): FormView
    {
        return $this->formFactory
            ->create(ReorderBoardColumnsFormType::class, new ReorderBoardColumnsRequest($order, $expectedOrder))
            ->createView();
    }

    /**
     * The board's column ids in order, with the column at $index swapped with
     * its neighbour $offset away, for a move left or right.
     *
     * @param list<BoardColumnView> $columns
     */
    public function boardColumnOrder(array $columns, int $index, int $offset): string
    {
        $ids = array_map(static fn (BoardColumnView $view): string => (string) $view->column->id, $columns);
        $other = $index + $offset;
        if (isset($ids[$index], $ids[$other])) {
            [$ids[$index], $ids[$other]] = [$ids[$other], $ids[$index]];
        }

        return implode(',', $ids);
    }

    /** A card body is Markdown, rendered and sanitized the same way a document's is. */
    public function cardBody(string $markdown): string
    {
        return $this->markdown->render($markdown);
    }

    /**
     * The URL when it is safe to put in an href, else null.
     *
     * A pull request link is kept exactly as it was given, from an agent as
     * readily as from a person, and escaping does not disarm a scheme: a
     * `javascript:` link would run on click. Only http and https reach an href,
     * and everything else is shown as text.
     */
    public function safePullRequestUrl(string $url): ?string
    {
        $trimmed = trim($url);
        $scheme = mb_strtolower($trimmed);

        return str_starts_with($scheme, 'http://') || str_starts_with($scheme, 'https://')
            ? $trimmed
            : null;
    }
}
