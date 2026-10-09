<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\PauseKind;
use Symfony\Component\Uid\Uuid;

/** What an action answers: it did its work, it was refused, or the card must pause. */
final readonly class ActionOutcome
{
    public const string CODE_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';

    private const int MAX_CODE_LENGTH = 64;

    private function __construct(
        public ActionOutcomeKind $kind,
        public ?string $code = null,
        public ?PauseKind $pauseKind = null,
        public bool $alreadyLive = false,
        public ?Uuid $requestId = null,
    ) {
    }

    /** Done. A request that the action opened gives its id, so the engine reads how it settles. */
    public static function done(?Uuid $requestId = null): self
    {
        return new self(ActionOutcomeKind::Done, requestId: $requestId);
    }

    /** Done, because a live work request of the kind already does the work. It opened nothing. */
    public static function alreadyLive(): self
    {
        return new self(ActionOutcomeKind::Done, alreadyLive: true);
    }

    public static function refused(string $code): self
    {
        return new self(ActionOutcomeKind::Refused, self::code($code));
    }

    public static function pause(PauseKind $kind, string $code): self
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
