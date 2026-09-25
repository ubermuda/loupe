<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\CliUpdateState;
use Symfony\Component\Validator\Constraints as Assert;

/** The update report inside a heartbeat. */
final class CliUpdateInput
{
    public function __construct(
        #[Assert\Choice(callback: [self::class, 'states'])]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\Length(max: Bridge::MAX_UPDATE_VERSION_LENGTH)]
        public ?string $version = null,
    ) {
    }

    /** @return list<string> */
    public static function states(): array
    {
        return array_map(static fn (CliUpdateState $state): string => $state->value, CliUpdateState::cases());
    }

    public function state(): CliUpdateState
    {
        return CliUpdateState::from($this->state ?? '');
    }
}
