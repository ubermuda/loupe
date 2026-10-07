<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class Parameter
{
    /** @param ?list<string> $choices the values a string parameter accepts, or null for any */
    public function __construct(
        public string $name,
        public ParameterType $type,
        public bool $required = true,
        public ?array $choices = null,
    ) {
    }
}
