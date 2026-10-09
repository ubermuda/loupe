<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Supplies one kind of facts about a card to the engine. The module that owns the data implements it. */
#[AutoconfigureTag('app.workflow_fact_provider')]
interface FactProvider
{
    /** @return class-string the class of the object that build() returns */
    public function factsClass(): string;

    /** False when the module that owns the facts is off on this instance. */
    public function isOn(): bool;

    /** The facts of one card, an instance of factsClass(). */
    public function build(CardSnapshot $card): object;

    /** The part of the facts whose change counts as a change, comparable across evaluations. */
    public function fingerprint(object $facts): mixed;

    /** The translation key of the label of the module that owns the facts. */
    public function source(): string;
}
