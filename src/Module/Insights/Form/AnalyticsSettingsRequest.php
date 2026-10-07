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
    ) {
    }

    public static function fromView(AnalyticsSettingsView $view): self
    {
        return new self($view->defaultModel, $view->defaultEffort, $view->collectFullText);
    }
}
