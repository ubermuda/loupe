<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One hook a bridge runs, and how its last run went. */
final class BridgeHookInput
{
    public const int MAX_PACKAGE_LENGTH = 300;

    public const int MAX_REF_LENGTH = 100;

    public const int MAX_ERROR_LENGTH = 500;

    public const array EVENTS = ['start', 'stop', 'busy', 'idle'];

    public const array OUTCOMES = ['ok', 'failed', 'timeout', 'never'];

    private const string RFC3339 = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d{1,9})?(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/Di';

    public function __construct(
        #[Assert\Length(max: self::MAX_PACKAGE_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $package = null,

        #[Assert\Length(max: self::MAX_REF_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $ref = null,

        #[Assert\Choice(choices: self::EVENTS)]
        #[Assert\NotBlank]
        public ?string $event = null,

        /** RFC 3339. Null for a hook that has not run since the bridge started. */
        #[Assert\Regex(pattern: self::RFC3339)]
        public ?string $lastRunAt = null,

        #[Assert\Choice(choices: self::OUTCOMES)]
        #[Assert\NotBlank]
        public ?string $outcome = null,

        #[Assert\Length(max: self::MAX_ERROR_LENGTH, normalizer: 'trim')]
        public ?string $error = null,
    ) {
    }

    /** The shape check passes February 31, which the date parser would roll into March. */
    #[Assert\IsTrue(message: 'This value is not a valid date.')]
    public function isLastRunAtADate(): bool
    {
        if (null === $this->lastRunAt || 1 !== preg_match(self::RFC3339, $this->lastRunAt)) {
            return true;
        }

        return checkdate((int) substr($this->lastRunAt, 5, 2), (int) substr($this->lastRunAt, 8, 2), (int) substr($this->lastRunAt, 0, 4));
    }
}
