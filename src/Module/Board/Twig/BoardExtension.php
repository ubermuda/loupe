<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use App\Module\Board\Entity\Card;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Form\MoveCardRequest;
use App\Module\Review\Service\MarkdownRenderer;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
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
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_move_form', $this->cardMoveForm(...)),
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
                new MoveCardRequest($card->status, $card->priority),
            )
            ->createView();
    }

    /** A card body is Markdown, rendered and sanitized the same way a document's is. */
    public function cardBody(string $markdown): string
    {
        return $this->markdown->render($markdown);
    }
}
