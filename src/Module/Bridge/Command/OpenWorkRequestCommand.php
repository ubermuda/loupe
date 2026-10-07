<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class OpenWorkRequestCommand
{
    public function __construct(
        public Project $project,
        public Uuid $cardId,
        public int $cardNumber,
        public string $kind,
        public ?string $capability,
        public string $ruleId,
        public WorkRequestContext $context,
        public ?string $prompt = null,
    ) {
    }
}
