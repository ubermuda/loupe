<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Search\SearchProviderInterface;
use App\Search\SearchResult;
use App\Search\SearchResults;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CardSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private BoardAvailability $board,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function search(Project $project, string $query, int $page): SearchResults
    {
        if ('' === $query || !$this->board->isEnabled()) {
            return new SearchResults();
        }
        if (1 === preg_match('/^#?([1-9][0-9]*)$/D', $query, $matches)) {
            $number = filter_var($matches[1], FILTER_VALIDATE_INT);
            $card = false !== $number && 1 === $page ? $this->cards->findOneBy(['project' => $project, 'number' => $number]) : null;
            $cards = null === $card ? [] : [$card];
        } else {
            $cards = $this->cards->searchByProject($project, $query, $page, self::PAGE_SIZE);
        }
        $items = [];
        foreach ($cards as $card) {
            $items[] = new SearchResult(
                '#'.$card->number.' '.$card->title,
                $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                'card',
            );
        }

        return new SearchResults($items, $page * self::PAGE_SIZE < count($cards));
    }
}
