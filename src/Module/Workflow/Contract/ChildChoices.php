<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Board asks this port about the choice an agent states for a card it files under a parent. The workflow template says what each choice does. */
interface ChildChoices
{
    public const string INHERIT = 'inherit';
    public const string OWN = 'own';
    public const array CHOICES = [self::INHERIT, self::OWN];
    /** The refusal code of a choice the workflow does not declare. */
    public const string NO_CHOICE = 'no-child-choice';

    /** @return array<string, list<ChildChoiceStep>> the steps of each choice by `inherit` or `own`, and none when the workflow declares no choice or does not run for the project */
    public function forProject(Uuid $projectId): array;

    /** Runs the steps of the choice on the card in order, and answers the refusal code of the first step that refuses, or null. */
    public function run(Uuid $cardId, string $choice): ?string;
}
