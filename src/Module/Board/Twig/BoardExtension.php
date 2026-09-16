<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Form\AddBoardColumnFormType;
use App\Module\Board\Form\AddBoardColumnRequest;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\DeleteBoardColumnRequest;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Form\MoveCardRequest;
use App\Module\Board\Form\RenameBoardColumnFormType;
use App\Module\Board\Form\RenameBoardColumnRequest;
use App\Module\Board\Form\ReorderBoardColumnsFormType;
use App\Module\Board\Form\ReorderBoardColumnsRequest;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\MarkdownRenderer;
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
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_move_form', $this->cardMoveForm(...)),
            new TwigFunction('board_column_add_form', $this->boardColumnAddForm(...)),
            new TwigFunction('board_column_rename_form', $this->boardColumnRenameForm(...)),
            new TwigFunction('board_column_delete_form', $this->boardColumnDeleteForm(...)),
            new TwigFunction('board_columns_reorder_form', $this->boardColumnsReorderForm(...)),
            new TwigFunction('board_column_order', $this->boardColumnOrder(...)),
            new TwigFunction('safe_pull_request_url', $this->safePullRequestUrl(...)),
            new TwigFunction('card_site_review_links', $this->cardSiteReviewLinks(...)),
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
                new MoveCardRequest($card->column, $card->priority),
                ['project' => $card->project],
            )
            ->createView();
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

    /** The refused form a failed add forwarded, or a fresh one. */
    public function boardColumnAddForm(?FormView $refused = null): FormView
    {
        return $refused ?? $this->formFactory->create(AddBoardColumnFormType::class, new AddBoardColumnRequest())->createView();
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
            ->createNamed($name, RenameBoardColumnFormType::class, new RenameBoardColumnRequest($this->translator->trans($column->label)))
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

    public function boardColumnsReorderForm(string $order = ''): FormView
    {
        return $this->formFactory
            ->create(ReorderBoardColumnsFormType::class, new ReorderBoardColumnsRequest($order))
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
