<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxActionKind;

/** One action that fits an inbox item now. The keys are translation keys. A disabled reason shows the action greyed out. */
final readonly class ItemAction
{
    public function __construct(
        public InboxActionKind $kind,
        public string $labelKey,
        public string $effectKey,
        public bool $default = false,
        public ?string $disabledReasonKey = null,
        /** The one-line reason of the worker that suggested the action, as plain text. */
        public ?string $reason = null,
        /** The command, its checks and its run state, for a run-command action alone. */
        public ?RunCommandView $runCommand = null,
        /** The prompt that the agent suggested for a start-session action. The owner edits it before the click. */
        public ?string $sessionPrompt = null,
    ) {
    }

    public static function for(InboxActionKind $kind, bool $default = false, ?string $disabledReasonKey = null, ?string $reason = null, ?RunCommandView $runCommand = null, ?string $sessionPrompt = null): self
    {
        return new self($kind, \sprintf('inbox.action.%s.label', $kind->value), \sprintf('inbox.action.%s.effect', $kind->value), $default, $disabledReasonKey, $reason, $runCommand, $sessionPrompt);
    }
}
