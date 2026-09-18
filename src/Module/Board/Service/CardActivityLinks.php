<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Outbox\ActivityLink;
use App\Outbox\ActivityLinkProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CardActivityLinks implements ActivityLinkProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private BoardAvailability $board,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function linksFor(Project $project, array $events): array
    {
        if (!$this->board->isEnabled()) {
            return [];
        }
        $cardIds = [];
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true);
            $subject = is_array($payload) ? ($payload['subject'] ?? null) : null;
            if (!is_array($subject) || 'card' !== ($subject['type'] ?? null)) {
                continue;
            }
            $id = $subject['id'] ?? null;
            if (is_string($id) && Uuid::isValid($id)) {
                $cardIds[(string) $event->id] = Uuid::fromString($id)->toRfc4122();
            }
        }
        $cardsById = [];
        if ([] !== $cardIds) {
            foreach ($this->cards->findBy(['project' => $project, 'id' => array_values(array_unique($cardIds))]) as $card) {
                $cardsById[(string) $card->id] = $card;
            }
        }
        $links = [];
        foreach ($cardIds as $eventId => $cardId) {
            $card = $cardsById[$cardId] ?? null;
            if (null !== $card) {
                $links[$eventId] = new ActivityLink(
                    $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                    '#'.$card->number.' '.$card->title,
                );
            }
        }

        return $links;
    }
}
