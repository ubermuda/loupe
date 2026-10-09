<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\ReadsDocumentTag;

final readonly class Template
{
    public const string EPIC_BRANCH_NUMBER = '{number}';

    public const int EPIC_BRANCH_MAX_LENGTH = 255;

    /** A Git branch name that holds the epic number placeholder exactly once. */
    public const string EPIC_BRANCH_RULE = '/^(?!.*\{number\}.*\{number\})(?!.*\.\.)(?!.*\.lock(?:\/|$))(?=.*\{number\})(?:[A-Za-z0-9_]|\{number\})(?:[A-Za-z0-9._-]|\{number\}|\/(?![\/.-]))*(?<![.\/])$/D';

    /**
     * @param list<TemplateCardType>          $types
     * @param list<Slot>                      $slots
     * @param list<Rule>                      $rules
     * @param list<ManualMove>                $manualMoves
     * @param list<int>                       $backoffMinutes
     * @param array<string, list<ActionCall>> $childChoices   the actions of each choice an agent states for a card it files under a parent, by `inherit` or `own`
     * @param ?string                         $epicBranch     the branch of an epic, with the card number as the placeholder, or null when the template has no epic branches
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $slots,
        public array $rules,
        public array $manualMoves,
        public array $backoffMinutes,
        public int $workTimeoutMinutes,
        public array $types,
        public string $defaultType,
        public ?WorkFailurePolicy $onWorkFailed = null,
        public array $childChoices = [],
        public ?string $epicBranch = null,
    ) {
    }

    public function epicBranchOf(int $number): ?string
    {
        return null === $this->epicBranch ? null : str_replace(self::EPIC_BRANCH_NUMBER, (string) $number, $this->epicBranch);
    }

    public function type(string $key): ?TemplateCardType
    {
        foreach ($this->types as $type) {
            if ($type->key === $key) {
                return $type;
            }
        }

        return null;
    }

    public function slot(string $key): ?Slot
    {
        foreach ($this->slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<Rule> the rules of the slot and the global rules that can act in it, in template order */
    public function rulesFor(?string $slot): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn (Rule $rule): bool => (null === $rule->slot || $rule->slot === $slot)
                && (null === $rule->then->from || $rule->then->from === $slot),
        ));
    }

    /** @return list<string> the document tags that the rules of the slot read, each once */
    public function documentTagsFor(?string $slot): array
    {
        $tags = [];
        foreach ($this->rulesFor($slot) as $rule) {
            foreach ($rule->when->leaves() as $leaf) {
                $tag = $leaf->condition instanceof ReadsDocumentTag ? $leaf->condition->documentTag($leaf->params) : null;
                if (null !== $tag) {
                    $tags[] = $tag;
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
