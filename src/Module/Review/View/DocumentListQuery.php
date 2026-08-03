<?php

declare(strict_types=1);

namespace App\Module\Review\View;

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
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            page: max(1, $query->getInt('page', 1)),
            includeArchived: $query->getBoolean('archived'),
        );
    }

    public function withPage(int $page): self
    {
        return new self($page, $this->includeArchived);
    }

    /** @return array{page: int, archived?: int} */
    public function routeParams(): array
    {
        $params = ['page' => $this->page];

        if ($this->includeArchived) {
            $params['archived'] = 1;
        }

        return $params;
    }
}
