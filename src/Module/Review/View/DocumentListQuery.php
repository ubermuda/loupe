<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Series;
use App\Module\Review\Entity\Tag;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Where the reader currently is in the documents list: the page, plus whichever
 * filters are on. Every link and redirect that must land the reader back where
 * they were goes through {@see routeParams()} — the clamp redirect, the
 * pagination links, the rename page and the archive actions — so a filter added
 * here reaches all of them at once instead of being threaded by hand and
 * silently dropped by whichever one is forgotten.
 *
 * A filter that is off is omitted from routeParams() so an unfiltered list keeps
 * its bare URL; the page is always emitted.
 */
final readonly class DocumentListQuery
{
    public function __construct(
        public int $page = 1,
        public bool $includeArchived = false,
        public ?string $search = null,
        public ?DocumentStatus $status = null,
        public ?string $tagName = null,
        public ?string $seriesName = null,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $search = trim($query->getString('search'));
        $tagName = trim($query->getString('tag'));
        $seriesName = trim($query->getString('series'));

        return new self(
            page: max(1, $query->getInt('page', 1)),
            includeArchived: $query->getBoolean('archived'),
            search: '' === $search ? null : $search,
            // An unknown status is dropped rather than rejected: a hand-edited
            // URL should show the unfiltered list, not a 404.
            status: DocumentStatus::tryFrom($query->getString('status')),
            tagName: '' === $tagName ? null : Tag::normalizeName($tagName),
            seriesName: '' === $seriesName ? null : Series::normalizeName($seriesName),
        );
    }

    /**
     * Clone-with rather than a constructor call, so a filter added to this class
     * cannot be dropped here — which is the failure the clamp redirect would
     * otherwise show as a filter vanishing on an out-of-range page.
     */
    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    /**
     * The same view with the archived toggle flipped, back at page one — the
     * chip that renders this must carry the other filters with it, or turning
     * archived on would silently drop the reader's search.
     */
    public function withIncludeArchived(bool $includeArchived): self
    {
        return clone ($this, ['page' => 1, 'includeArchived' => $includeArchived]);
    }

    /**
     * Whether the reader has narrowed the list — which is what separates "no
     * documents yet" from "nothing matched". The archived filter is deliberately
     * excluded: it widens the list, so an empty result under it still means the
     * project has no documents.
     */
    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->status || null !== $this->tagName || null !== $this->seriesName;
    }

    /** @return array{page: int, archived?: int, search?: string, status?: string, tag?: string, series?: string} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if ($this->includeArchived) {
            $params['archived'] = 1;
        }

        if (null !== $this->search) {
            $params['search'] = $this->search;
        }

        if (null !== $this->status) {
            $params['status'] = $this->status->value;
        }

        if (null !== $this->tagName) {
            $params['tag'] = $this->tagName;
        }

        if (null !== $this->seriesName) {
            $params['series'] = $this->seriesName;
        }

        return $params;
    }
}
