<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

final class UnknownCondition extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $key,
    ) {
        parent::__construct(\sprintf('No workflow condition has the key "%s".', $key));
    }
}
