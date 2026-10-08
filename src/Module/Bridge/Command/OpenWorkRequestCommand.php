<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;

final readonly class OpenWorkRequestCommand
{
    public function __construct(
        public Project $project,
        public WorkSubject $subject,
        /** The card number a person sees. A card subject needs one, and any other subject has none. */
        public ?int $cardNumber,
        public string $kind,
        public ?string $capability,
        public string $ruleId,
        public WorkRequestContext $context,
        public ?string $model = null,
        public ?string $effort = null,
        public ?string $prompt = null,
    ) {
    }
}
