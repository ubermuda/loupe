<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Context\ContextLabel;
use App\Module\SiteReview\Context\ContextLabelResolverInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Names the card a `card:<uuid>` marker points at.
 *
 * Board implements a SiteReview interface rather than SiteReview reading a
 * card, so the dependency runs the way the arkitect rule fencing Board as a
 * leaf requires.
 *
 * Every refusal returns null and the widget then says nothing, which is the
 * point: a marker naming another project's card is refused when the comment is
 * saved, and a reviewer who was told the card's name would have been told a
 * lie.
 */
final readonly class CardContextLabelResolver implements ContextLabelResolverInterface
{
    private const string PREFIX = 'card:';

    public function __construct(
        private CardRepository $cards,
        private BoardAvailability $board,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function resolve(string $context, Project $project): ?ContextLabel
    {
        if (!str_starts_with($context, self::PREFIX) || !$this->board->isEnabled()) {
            return null;
        }

        $id = substr($context, \strlen(self::PREFIX));
        if (!Uuid::isValid($id)) {
            return null;
        }

        $card = $this->cards->find(Uuid::fromString($id));
        // The project check is the same one the comment listener applies. A
        // widget token belongs to one project, and the marker arrives from a
        // page anyone can edit.
        if (null === $card || $card->project->id != $project->id) {
            return null;
        }

        return new ContextLabel(
            \sprintf('#%d %s', $card->number, $card->title),
            $this->urls->generate(
                'app_board_card',
                ['projectId' => (string) $project->id, 'cardId' => (string) $card->id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        );
    }
}
