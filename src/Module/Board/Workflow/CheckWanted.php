<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

/** The site review check one pull request should carry, beside the check Loupe last posted. */
final readonly class CheckWanted
{
    public const string SUCCESS = 'success';

    public const string FAILURE = 'failure';

    public function __construct(
        public string $headSha,
        /** @var self::SUCCESS|self::FAILURE */
        public string $wantedConclusion,
        public int $noteCount,
        public ?string $postedSha,
        public ?string $postedConclusion,
        public ?int $postedRunId,
        public ?int $postedNoteCount,
        public string $notesDigest,
        public ?string $postedNotesDigest,
    ) {
    }
}
