<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

final class UnknownTemplate extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $key,
    ) {
        parent::__construct(\sprintf('No shipped workflow template has the key "%s".', $key));
    }
}
