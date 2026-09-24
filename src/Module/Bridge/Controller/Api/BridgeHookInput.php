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
        /** Null for a hook that has not run since the bridge started. */
        public ?\DateTimeImmutable $lastRunAt = null,

        #[Assert\Choice(choices: self::OUTCOMES)]
        #[Assert\NotBlank]
        public ?string $outcome = null,

        #[Assert\Length(max: self::MAX_ERROR_LENGTH, normalizer: 'trim')]
        public ?string $error = null,
    ) {
    }
}
