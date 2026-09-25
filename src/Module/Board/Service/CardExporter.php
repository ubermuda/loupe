<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The board cards in the account data export.
 *
 * It reads no feature flag. An export states what the account holds, and cards
 * written while the board was on stay the account's data after it goes off.
 */
final readonly class CardExporter implements UserDataExporterInterface
{
    public function __construct(
        private CardRepository $cards,
        private CardLinkRepository $cardLinks,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'cards.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $cards = $this->cards->findByOwner($user);
        $links = $this->cardLinks->findForCards($cards);

        foreach ($cards as $card) {
            yield [
                'id' => (string) $card->id,
                'project' => $card->project->name,
                'title' => $card->title,
                'body' => $card->body,
                'status' => $card->column->slug,
                'column' => $this->translator->trans($card->column->label),
                'type' => $card->type->value,
                'reporter' => $card->reporter->value,
                'position' => $card->position,
                'completedAt' => $card->completedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $card->createdAt->format(\DateTimeInterface::ATOM),
                'updatedAt' => $card->updatedAt->format(\DateTimeInterface::ATOM),
                'pullRequests' => array_values(array_map(
                    static fn (CardPullRequest $link): array => [
                        'url' => $link->url,
                        'forge' => $link->forge->value,
                        'repository' => $link->repository,
                        'number' => $link->number,
                        'addedAt' => $link->addedAt->format(\DateTimeInterface::ATOM),
                    ],
                    $card->pullRequests->toArray(),
                )),
                // Ids alone: a document's own text belongs to the Review exporter.
                'documents' => array_values(array_map(
                    static fn (CardDocument $link): string => (string) $link->document->id,
                    $card->documents->toArray(),
                )),
                // In full, because feedback has no file of its own.
                'feedback' => array_map(
                    static fn (CardSiteReviewComment $link): array => [
                        'id' => (string) $link->comment->id,
                        'url' => $link->comment->url,
                        'context' => $link->comment->context,
                        'anchors' => array_values(array_map(
                            static fn (SiteReviewCommentAnchor $anchor): array => [
                                'selector' => $anchor->selector,
                                'text' => $anchor->text,
                                'quote' => $anchor->quote,
                                'quotePrefix' => $anchor->quotePrefix,
                                'quoteSuffix' => $anchor->quoteSuffix,
                            ],
                            $link->comment->anchors->toArray(),
                        )),
                        'body' => $link->comment->body,
                        'strokes' => $link->comment->strokes ?? [],
                        'status' => $link->comment->status->value,
                        'createdAt' => $link->comment->createdAt->format(\DateTimeInterface::ATOM),
                    ],
                    $this->cardSiteReviewComments->findForCard($card),
                ),
                'relatedCards' => array_map(
                    static fn (CardLink $link): array => [
                        'cardId' => (string) $link->otherThan($card)->id,
                        'number' => $link->otherThan($card)->number,
                        'kind' => $link->kindFor($card)->value,
                    ],
                    $links[(string) $card->id] ?? [],
                ),
                'parentCardId' => null === $card->parent ? null : (string) $card->parent->id,
                'parentNumber' => $card->parent?->number,
                'laneEnabled' => $card->laneEnabled,
            ];
        }
    }
}
