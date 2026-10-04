<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

final class InvalidTemplate extends \InvalidArgumentException
{
    /** @param non-empty-list<string> $errors */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct("The workflow template is invalid:\n- ".implode("\n- ", $errors));
    }
}
