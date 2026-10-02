<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Board\Entity\CardPauseKind;

/** What an action answers: it did its work, it was refused, or the card must pause. */
final readonly class ActionOutcome
{
    public const string CODE_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    private const int MAX_CODE_LENGTH = 64;

    private function __construct(
        public ActionOutcomeKind $kind,
        public ?string $code = null,
        public ?CardPauseKind $pauseKind = null,
    ) {
    }

    public static function done(): self
    {
        return new self(ActionOutcomeKind::Done);
    }

    public static function refused(string $code): self
    {
        return new self(ActionOutcomeKind::Refused, self::code($code));
    }

    public static function pause(CardPauseKind $kind, string $code): self
    {
        return new self(ActionOutcomeKind::Pause, self::code($code), $kind);
    }

    /** Turns a cause, such as `api_failed_rate_limited`, into a code that matches CODE_PATTERN. */
    public static function code(string $cause): string
    {
        $code = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cause)) ?? '', '-');
        if ('' === $code) {
            return 'unknown';
        }
        if (!ctype_alpha($code[0])) {
            $code = 'code-'.$code;
        }

        return rtrim(substr($code, 0, self::MAX_CODE_LENGTH), '-');
    }
}
