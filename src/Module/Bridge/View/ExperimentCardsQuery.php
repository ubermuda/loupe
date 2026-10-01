<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use Symfony\Component\HttpFoundation\InputBag;

/** The filter and the page of the Cards tab of an experiment. */
final readonly class ExperimentCardsQuery
{
    public function __construct(
        public ?string $variant = null,
        public bool $leftOutOnly = false,
        public int $page = 1,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $variant = trim($query->getString('variant'));
        $leftOutOnly = '1' === $query->getString('left-out');

        // The two filters exclude each other, so the left-out one wins.
        return new self(
            variant: '' === $variant || $leftOutOnly ? null : $variant,
            leftOutOnly: $leftOutOnly,
            page: max(1, $query->getInt('page', 1)),
        );
    }

    public function withPage(int $page): self
    {
        return clone ($this, ['page' => $page]);
    }

    /** @return array{variant?: string, left-out?: string, page?: int} */
    public function routeParams(): array
    {
        $params = [];
        if (null !== $this->variant) {
            $params['variant'] = $this->variant;
        }
        if ($this->leftOutOnly) {
            $params['left-out'] = '1';
        }
        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        return $params;
    }
}
