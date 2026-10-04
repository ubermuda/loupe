<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

final readonly class WorkflowTemplateChoice
{
    /**
     * @param string $label       a translation key
     * @param string $description a translation key
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
    ) {
    }
}
