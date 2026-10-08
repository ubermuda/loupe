<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Command\AnalyticsSettingsView;

class AnalyticsSettingsRequest
{
    public function __construct(
        /** Null clears the project value. */
        public ?string $model = null,
        /** Null clears the project value. */
        public ?string $effort = null,
        public bool $collectFullText = false,
        /** A comma list. Blank clears the project list. */
        public ?string $subcommandPrograms = null,
    ) {
    }

    /** @return list<string>|null null for a blank list */
    public function subcommandProgramList(): ?array
    {
        $programs = array_values(array_filter(
            array_map(trim(...), explode(',', $this->subcommandPrograms ?? '')),
            static fn (string $program): bool => '' !== $program,
        ));

        return [] === $programs ? null : $programs;
    }

    public static function fromView(AnalyticsSettingsView $view): self
    {
        return new self($view->defaultModel, $view->defaultEffort, $view->collectFullText, null === $view->subcommandPrograms ? null : implode(', ', $view->subcommandPrograms));
    }
}
