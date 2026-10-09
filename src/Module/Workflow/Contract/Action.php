<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Carries out the action of a rule on one card. A template refers to it by its key. */
#[AutoconfigureTag('app.workflow_action')]
interface Action
{
    public static function key(): string;

    /** The translation key of the module that owns the action. */
    public static function source(): string;

    /** @return list<Parameter> */
    public static function parameters(): array;

    public static function traits(): ActionTraits;

    /** @param array<string, mixed> $params */
    public function describe(array $params): ActionDescription;

    /**
     * The kind of work the action asks a bridge for with these parameters, or null for an action that asks for none.
     *
     * @param array<string, mixed> $params
     */
    public function workKind(array $params): ?string;

    public function run(ActionContext $context): ActionOutcome;
}
