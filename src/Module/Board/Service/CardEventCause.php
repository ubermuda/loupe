<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use Symfony\Component\Uid\Uuid;

/** Why a card moved: an app rule, a workflow rule, or the run of an agent. A history row stores it as detail(). */
final readonly class CardEventCause
{
    /** @param array<string, scalar> $fields */
    private function __construct(
        public string $type,
        public array $fields = [],
    ) {
    }

    public static function merged(int $prNumber): self
    {
        return new self('merged', ['pullRequest' => $prNumber]);
    }

    public static function checksPassed(int $prNumber): self
    {
        return new self('checks-passed', ['pullRequest' => $prNumber]);
    }

    public static function documentApproved(string $title): self
    {
        return new self('document-approved', ['document' => $title]);
    }

    public static function epicReconciled(int $childNumber): self
    {
        return new self('epic-reconciled', ['child' => $childNumber]);
    }

    /** @param int|null $blockerNumber null when no single blocker move freed the card, such as a removed link */
    public static function unblocked(?int $blockerNumber = null): self
    {
        return new self('unblocked', null === $blockerNumber ? [] : ['blocker' => $blockerNumber]);
    }

    public static function abandoned(): self
    {
        return new self('abandoned');
    }

    public static function columnDeleted(string $label): self
    {
        return new self('column-deleted', ['column' => $label]);
    }

    /** A run of an old bridge rule has no work kind. */
    public static function run(Uuid|string $runId, ?string $workKind): self
    {
        return new self('run', ['run' => (string) $runId] + (null === $workKind ? [] : ['kind' => $workKind]));
    }

    public static function workflowRule(string $ruleId): self
    {
        return new self('workflow-rule', ['rule' => $ruleId]);
    }

    /** @return array<string, scalar> */
    public function detail(): array
    {
        return ['type' => $this->type] + $this->fields;
    }
}
