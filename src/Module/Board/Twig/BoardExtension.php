<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\CardProgress;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Form\AddBoardColumnFormType;
use App\Module\Board\Form\AddBoardColumnRequest;
use App\Module\Board\Form\ConfigureBoardColumnFormType;
use App\Module\Board\Form\ConfigureBoardColumnRequest;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\DeleteBoardColumnRequest;
use App\Module\Board\Form\MoveBacklogCardFormType;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Form\MoveCardRequest;
use App\Module\Board\Form\ReorderBoardColumnsFormType;
use App\Module\Board\Form\ReorderBoardColumnsRequest;
use App\Module\Board\Form\SetCardLaneFormType;
use App\Module\Board\Form\SetCardLaneRequest;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\BoardColumnTonePicker;
use App\Module\Board\Service\CardBadge;
use App\Module\Board\Service\CardDigest;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\View\DocumentListItem;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The board face renders the move fields of one prototype form per project,
 * then puts each card's own form name in place of the prototype's name.
 */
final class BoardExtension extends AbstractExtension implements ResetInterface
{
    private const array MOVE_FIELDS = ['_token', 'column', 'position', 'parent', 'beforeCardId', 'afterCardId', 'unmanage'];

    private readonly string $prototypeName;

    /** @var array<string, string> project id => rendered prototype fields */
    private array $moveFields = [];

    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly MarkdownRenderer $markdown,
        private readonly TranslatorInterface $translator,
        private readonly CardDocumentRepository $cardDocuments,
        private readonly BoardColumnRepository $boardColumns,
        private readonly BoardColumnTonePicker $tonePicker,
        private readonly CardDigest $digest,
    ) {
        $this->prototypeName = 'move_card_'.bin2hex(random_bytes(8));
    }

    #[\Override]
    public function reset(): void
    {
        $this->moveFields = [];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_move_form', $this->cardMoveForm(...)),
            new TwigFunction('card_move_fields', $this->cardMoveFields(...), ['needs_environment' => true, 'is_safe' => ['html']]),
            new TwigFunction('card_lane_form', $this->cardLaneForm(...)),
            // The Backlog page writes this form by hand, so 25 rows build no column choices.
            new TwigFunction('backlog_move_form_name', MoveBacklogCardFormType::nameFor(...)),
            new TwigFunction('card_digest', $this->cardDigest(...)),
            new TwigFunction('lane_head_digest', $this->digest->forLaneHead(...)),
            new TwigFunction('board_column_add_form', $this->boardColumnAddForm(...)),
            new TwigFunction('board_column_configure_form', $this->boardColumnConfigureForm(...)),
            new TwigFunction('board_column_delete_form', $this->boardColumnDeleteForm(...)),
            new TwigFunction('board_columns_reorder_form', $this->boardColumnsReorderForm(...)),
            new TwigFunction('board_column_order', $this->boardColumnOrder(...)),
            new TwigFunction('safe_pull_request_url', $this->safePullRequestUrl(...)),
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

    /** The column select is left unselected, because the drag controller writes it before it submits. */
    public function cardMoveFields(Environment $env, Card $card): string
    {
        $html = $this->moveFields[(string) $card->project->id] ??= $this->renderMovePrototype($env, $card->project);

        return str_replace($this->prototypeName, MoveCardFormType::nameFor($card), $html);
    }

    private function renderMovePrototype(Environment $env, Project $project): string
    {
        $view = $this->formFactory
            ->createNamed($this->prototypeName, MoveCardFormType::class, new MoveCardRequest(), ['project' => $project])
            ->createView();
        $renderer = $env->getRuntime(FormRenderer::class);

        return implode('', array_map(
            static fn (string $field): string => $renderer->searchAndRenderBlock($view[$field], 'widget'),
            self::MOVE_FIELDS,
        ));
    }

    /** A form that asks for the opposite of the lane setting the epic holds now. */
    public function cardLaneForm(Card $card, string $returnTo): FormView
    {
        return $this->formFactory
            ->createNamed(
                SetCardLaneFormType::nameFor($card),
                SetCardLaneFormType::class,
                new SetCardLaneRequest($card->laneEnabled ? '0' : '1', $returnTo),
            )
            ->createView();
    }

    /** @param list<CardBadge> $badges */
    public function cardDigest(Card $card, int $pendingComments, int $documentCount, ?CardProgress $progress, ?CardRunWarning $runWarning, array $badges): string
    {
        return $this->digest->forCard($card, $pendingComments, $documentCount, $card->pullRequests->count(), $progress, $runWarning, $badges);
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
     * The refused form a failed configure forwarded, when it belongs to this
     * column, or a fresh one that shows the column as it is now.
     */
    public function boardColumnConfigureForm(BoardColumn $column, ?FormView $refused = null): FormView
    {
        $name = ConfigureBoardColumnFormType::nameFor($column);
        if (null !== $refused && $refused->vars['name'] === $name) {
            return $refused;
        }

        return $this->formFactory
            ->createNamed($name, ConfigureBoardColumnFormType::class, new ConfigureBoardColumnRequest(
                label: $this->translator->trans($column->label),
                expectedLabel: $column->label,
                terminal: $column->terminal,
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
