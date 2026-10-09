<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

interface ReadsCardType
{
    /**
     * The card type the condition reads.
     *
     * @param array<string, mixed> $params
     */
    public function cardType(array $params): string;
}
