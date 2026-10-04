<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Translation\TranslatableMessage;

/** A named test over the facts of one card. A template refers to it by its key. */
#[AutoconfigureTag('app.workflow_condition')]
interface Condition
{
    public static function key(): string;

    /** @return list<Parameter> */
    public static function parameters(): array;

    /**
     * @param array<string, mixed> $params
     *
     * @return list<FactKey>
     */
    public function reads(array $params): array;

    /** @param array<string, mixed> $params */
    public function evaluate(Facts $facts, array $params): bool;

    /**
     * Plain: the condition is false and the rule needs it true. Negated: the condition is true and the rule needs it false.
     *
     * @param array<string, mixed> $params
     */
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage;
}
