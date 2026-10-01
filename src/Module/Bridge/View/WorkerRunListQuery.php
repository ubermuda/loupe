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
    /** The `outcome` word of the open runs filter. No state has this value. */
    public const string OPEN = 'open';

    public function __construct(
        public int $page = 1,
        public ?string $search = null,
        public ?WorkerRunState $state = null,
        public ?Uuid $bridgeId = null,
        /** Every open run, whatever its state. It shares the `outcome` word with $state, so at most one of them is set. */
        public bool $open = false,
        /**
         * The filters below reach the list through the MCP tools alone, so the
         * page URL never carries them.
         *
         * @var list<WorkerRunState> any of these states; empty keeps every state
         */
        public array $states = [],
        public ?int $cardNumber = null,
        public ?string $rule = null,
        /** Both bounds are inclusive, and read the end of a run, or the time of its first report when it has no end. */
        public ?\DateTimeImmutable $endedAfter = null,
        public ?\DateTimeImmutable $endedBefore = null,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));
        $bridgeId = trim($query->getString('bridge'));
        $outcome = $query->getString('outcome');

        return new self(
            page: max(1, $query->getInt('page', 1)),
            search: '' === $search ? null : $search,
            // An unknown value is dropped rather than refused: a hand-edited URL
            // should show the unfiltered list, not a 404. The query word stays
            // `outcome`, so a saved link keeps working.
            state: WorkerRunState::tryFrom($outcome),
            bridgeId: Uuid::isValid($bridgeId) ? Uuid::fromString($bridgeId) : null,
            open: self::OPEN === $outcome,
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
        return null !== $this->search || null !== $this->state || $this->open || null !== $this->bridgeId
            || [] !== $this->states || null !== $this->cardNumber || null !== $this->rule
            || null !== $this->endedAfter || null !== $this->endedBefore;
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

        if ($this->open) {
            $params['outcome'] = self::OPEN;
        }

        if (null !== $this->bridgeId) {
            $params['bridge'] = (string) $this->bridgeId;
        }

        return $params;
    }
}
