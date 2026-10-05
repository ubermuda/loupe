<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Translation\TranslatableMessage;

/** Reads a facts class that no provider gives. */
final readonly class UnprovidedFactsReady implements Condition
{
    public const string KEY = 'test.unprovided_ready';

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [\stdClass::class];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $facts->get(\stdClass::class);

        return true;
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.test_unprovided_ready');
    }
}
