<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class SendCardVerdictCommand
{
    /** @param list<string> $pullRequestIds the forge pull request ids the reviewer picked */
    public function __construct(
        public Project $project,
        public string $cardId,
        public User $reviewer,
        public CardVerdictKind $kind,
        public array $pullRequestIds,
        public string $message,
        public Uuid $submissionId,
    ) {
    }
}
