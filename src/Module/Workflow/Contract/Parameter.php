<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class Parameter
{
    public function __construct(
        public string $name,
        public ParameterType $type,
        public bool $required = true,
    ) {
    }
}
