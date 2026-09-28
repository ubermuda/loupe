<?php

declare(strict_types=1);

namespace App\Outbox\View;

use App\Outbox\ActivityFamily;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Where the reader currently is in the activity list: the page, plus whichever
 * filters are on. A filter that is off is omitted from routeParams(), so an
 * unfiltered list keeps its bare URL. The page is always emitted.
 */
final readonly class ActivityListQuery
{
    public function __construct(
        public int $page = 1,
        public ?string $search = null,
        public ?ActivityFamily $family = null,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));

        return new self(
            page: max(1, $query->getInt('page', 1)),
            search: '' === $search ? null : $search,
            // An unknown value is dropped rather than refused, so a hand-edited URL shows the unfiltered list.
            family: ActivityFamily::tryFrom($query->getString('family')),
        );
    }

    /** Clone-with, so a filter added to this class cannot be dropped by the clamp redirect. */
    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    /** Whether the reader has narrowed the list, which separates "no events yet" from "nothing matched". */
    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->family;
    }

    /** @return array{page: int, search?: string, family?: string} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if (null !== $this->search) {
            $params['search'] = $this->search;
        }

        if (null !== $this->family) {
            $params['family'] = $this->family->value;
        }

        return $params;
    }
}
