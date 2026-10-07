<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

/**
 * The variant a card runs with in one experiment. The candidate is one of the
 * variants. The weights name each variant with its weight, and are null when
 * the bridge sent no valid list. The metrics are the keys the experiment
 * declares, and are null when the bridge sent no valid list.
 */
final readonly class ResolveExperimentPinCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
        public Uuid $cardId,
        public string $experiment,
        public string $candidate,
        /** @var non-empty-list<string> */
        public array $variants,
        /** @var list<array{name: string, weight: int}>|null */
        public ?array $weights = null,
        /** @var list<string>|null */
        public ?array $metrics = null,
    ) {
    }
}
