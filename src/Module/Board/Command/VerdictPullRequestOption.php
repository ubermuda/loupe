<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Symfony\Component\Uid\Uuid;

/** An open pull request of a card that a verdict can name. */
final readonly class VerdictPullRequestOption
{
    public function __construct(
        public Uuid $id,
        /** The pull request as `owner/repo#n`. */
        public string $label,
        /** Whether the reviewer opened it, which GitHub does not let the reviewer approve. */
        public bool $ownPullRequest,
    ) {
    }
}
