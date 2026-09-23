<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Uid\Uuid;

/**
 * Where the reader currently is in the worker run list: the page, plus whichever
 * filters are on. Every link and redirect that must land the reader back where
 * they were reads {@see routeParams()}, so a filter added here reaches all of
 * them at once.
 *
 * A filter that is off is omitted from routeParams(), so an unfiltered list
 * keeps its bare URL. The page is always emitted.
 */
final readonly class WorkerRunListQuery
{
    public function __construct(
        public int $page = 1,
        public ?string $search = null,
        public ?WorkerRunState $state = null,
        public ?Uuid $bridgeId = null,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));
        $bridgeId = trim($query->getString('bridge'));

        return new self(
            page: max(1, $query->getInt('page', 1)),
            search: '' === $search ? null : $search,
            // An unknown value is dropped rather than refused: a hand-edited URL
            // should show the unfiltered list, not a 404. The query word stays
            // `outcome`, so a saved link keeps working.
            state: WorkerRunState::tryFrom($query->getString('outcome')),
            bridgeId: Uuid::isValid($bridgeId) ? Uuid::fromString($bridgeId) : null,
        );
    }

    /**
     * Clone-with rather than a constructor call, so a filter added to this class
     * cannot be dropped here. The clamp redirect would otherwise show that as a
     * filter vanishing on an out-of-range page.
     */
    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    /** Whether the reader has narrowed the list, which separates "no runs yet" from "nothing matched". */
    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->state || null !== $this->bridgeId;
    }

    /** @return array{page: int, search?: string, outcome?: string, bridge?: string} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if (null !== $this->search) {
            $params['search'] = $this->search;
        }

        if (null !== $this->state) {
            $params['outcome'] = $this->state->value;
        }

        if (null !== $this->bridgeId) {
            $params['bridge'] = (string) $this->bridgeId;
        }

        return $params;
    }
}
