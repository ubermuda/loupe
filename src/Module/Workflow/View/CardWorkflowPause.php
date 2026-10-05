<?php

declare(strict_types=1);

namespace App\Module\Workflow\View;

final readonly class CardWorkflowPause
{
    public function __construct(
        public string $id,
        public string $code,
        public string $kind,
        public string $reason,
        public string $release,
        public \DateTimeImmutable $since,
        /** A person may end the pause, because its kind allows it and the workflow manages the card. */
        public bool $releasable,
    ) {
    }
}
