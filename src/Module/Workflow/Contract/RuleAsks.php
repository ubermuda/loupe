<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Puts a question of a rule in front of the project owner and takes it back. The inbox implements it. */
interface RuleAsks
{
    /** True while the inbox can take a question on this instance for the project. */
    public function isOn(Uuid $projectId): bool;

    /**
     * Opens a question about a card. The answer comes back as the message RunRuleAskAnswer with the item id and the option index.
     *
     * @param list<string> $options the translated labels, in template order
     *
     * @return Uuid the id of the item
     */
    public function open(Uuid $projectId, Uuid $cardId, string $ruleId, string $question, array $options): Uuid;

    /** Closes an open question with no answer. An item that is closed already stays as it is. */
    public function withdraw(Uuid $itemId, string $reason): void;
}
