<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\BoardEventType;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Outbox\ActivityLink;
use App\Outbox\ActivityLinkProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CardActivityLinks implements ActivityLinkProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function linksFor(Project $project, array $events): array
    {
        $cardIds = [];
        $moves = [];
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true);
            $subject = is_array($payload) ? ($payload['subject'] ?? null) : null;
            if (!is_array($payload) || !is_array($subject) || 'card' !== ($subject['type'] ?? null)) {
                continue;
            }
            $id = $subject['id'] ?? null;
            if (is_string($id) && Uuid::isValid($id)) {
                $cardIds[(string) $event->id] = Uuid::fromString($id)->toRfc4122();
                $from = $payload['fromStatus'] ?? null;
                $to = $payload['toStatus'] ?? null;
                if (BoardEventType::CARD_MOVED === $event->type && is_string($from) && is_string($to)) {
                    $moves[(string) $event->id] = [$from, $to];
                }
            }
        }
        $cardsById = [];
        if ([] !== $cardIds) {
            foreach ($this->cards->findBy(['project' => $project, 'id' => array_values(array_unique($cardIds))]) as $card) {
                $cardsById[(string) $card->id] = $card;
            }
        }
        $labels = [] === $moves ? [] : $this->columnLabels($project);
        $links = [];
        foreach ($cardIds as $eventId => $cardId) {
            $card = $cardsById[$cardId] ?? null;
            if (null !== $card) {
                $move = $moves[$eventId] ?? null;
                $links[$eventId] = new ActivityLink(
                    $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                    '#'.$card->number.' '.$card->title,
                    null === $move ? null : $this->moveSubject($card, $move, $labels),
                );
            }
        }

        return $links;
    }

    /** @return array<string, string> the translated label of each column, keyed by slug */
    private function columnLabels(Project $project): array
    {
        $labels = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $labels[$column->slug] = $this->translator->trans($column->label);
        }

        return $labels;
    }

    /**
     * @param array{string, string} $move
     * @param array<string, string> $labels
     */
    private function moveSubject(Card $card, array $move, array $labels): string
    {
        [$from, $to] = $move;

        return '#'.$card->number.' '.($labels[$from] ?? $from).' → '.($labels[$to] ?? $to);
    }
}
