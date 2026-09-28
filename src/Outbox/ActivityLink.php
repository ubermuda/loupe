<?php

declare(strict_types=1);

namespace App\Outbox;

final readonly class ActivityLink
{
    /** What the event did to the linked work, such as the columns of a move. The label by default. */
    public string $subject;

    public function __construct(
        public string $url,
        public string $label,
        ?string $subject = null,
    ) {
        $this->subject = $subject ?? $label;
    }
}
