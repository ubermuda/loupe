<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\ValueObject\CostGroup;
use App\Module\Bridge\ValueObject\CostRange;
use App\Module\Bridge\ValueObject\CostSplit;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The controls of the cost tab. A control at its default is omitted from
 * routeParams(), so the default view keeps its bare URL.
 */
final readonly class WorkerRunCostQuery
{
    public function __construct(
        public CostRange $range = CostRange::NinetyDays,
        public CostSplit $split = CostSplit::None,
        public ?string $rule = null,
        public ?string $model = null,
        /** Null follows the range, as effectiveGroup() says. */
        public ?CostGroup $group = null,
    ) {
    }

    /** @param InputBag<string> $query */
    public static function fromQuery(InputBag $query): self
    {
        $rule = trim($query->getString('rule'));
        $model = trim($query->getString('model'));

        // An unknown value falls back to the default, so a hand-edited URL shows the chart.
        return new self(
            range: CostRange::tryFrom($query->getString('range')) ?? CostRange::NinetyDays,
            split: CostSplit::tryFrom($query->getString('split')) ?? CostSplit::None,
            rule: '' === $rule ? null : $rule,
            model: '' === $model ? null : $model,
            group: CostGroup::tryFrom($query->getString('group')),
        );
    }

    /** A new range drops the group, so the default of that range applies. */
    public function withRange(CostRange $range): self
    {
        return clone ($this, ['range' => $range, 'group' => null]);
    }

    public function withGroup(CostGroup $group): self
    {
        return clone ($this, ['group' => $group]);
    }

    public function effectiveGroup(): CostGroup
    {
        return $this->group ?? CostGroup::defaultFor($this->range);
    }

    public function withSplit(CostSplit $split): self
    {
        return clone ($this, ['split' => $split]);
    }

    public function isFiltered(): bool
    {
        return null !== $this->rule || null !== $this->model;
    }

    /** @return array{range?: string, split?: string, group?: string, rule?: string, model?: string} */
    public function routeParams(): array
    {
        $params = [];

        if (CostRange::NinetyDays !== $this->range) {
            $params['range'] = $this->range->value;
        }

        if (CostSplit::None !== $this->split) {
            $params['split'] = $this->split->value;
        }

        if (null !== $this->group && CostGroup::defaultFor($this->range) !== $this->group) {
            $params['group'] = $this->group->value;
        }

        if (null !== $this->rule) {
            $params['rule'] = $this->rule;
        }

        if (null !== $this->model) {
            $params['model'] = $this->model;
        }

        return $params;
    }
}
