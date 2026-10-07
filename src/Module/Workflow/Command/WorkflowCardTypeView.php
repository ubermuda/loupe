<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

final readonly class WorkflowCardTypeView
{
    /**
     * @param string $labelKey a translation key
     * @param string $tone     a LabelTone value
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $tone,
        public bool $children,
        public bool $lane,
        public bool $isDefault,
    ) {
    }
}
