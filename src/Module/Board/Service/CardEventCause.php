<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** Why the app moved a card on its own. A history row stores it as detail(). */
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

    public static function unblocked(int $blockerNumber): self
    {
        return new self('unblocked', ['blocker' => $blockerNumber]);
    }

    public static function abandoned(): self
    {
        return new self('abandoned');
    }

    public static function columnDeleted(string $label): self
    {
        return new self('column-deleted', ['column' => $label]);
    }

    /** @return array<string, scalar> */
    public function detail(): array
    {
        return ['type' => $this->type] + $this->fields;
    }
}
