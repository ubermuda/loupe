<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One account a bridge runs its workers under, and whether its last check passed. */
final class BridgeAccountInput
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const string HARNESS_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public const string STATE_READY = 'ready';

    public const string STATE_FAILING = 'failing';

    public const int MAX_REASON_LENGTH = 200;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::NAME_PATTERN)]
        public ?string $name = null,

        #[Assert\NotBlank]
        #[Assert\Regex(pattern: self::HARNESS_PATTERN)]
        public ?string $harness = null,

        #[Assert\Choice(choices: [self::STATE_READY, self::STATE_FAILING])]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\Length(max: self::MAX_REASON_LENGTH, normalizer: 'trim')]
        public ?string $reason = null,
    ) {
    }
}
