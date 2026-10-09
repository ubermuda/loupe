<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/**
 * An action whose rule fires again when the facts it reads change while it stays true. The action must
 * be safe to run twice, because each change of the facts runs it again.
 */
interface RefiresOnFactChange
{
    /** @param array<string, int|string> $params */
    public static function refiresOnFactChange(array $params): bool;
}
