<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopConnection
{
    public function __construct(
        public string $id,
        public string $cliVersion,
        public bool $quiet,
        public string $url,
        /** What a page calls the bridge: its name, or the tail of its id. */
        public string $label,
        /** The GitHub user the bridge pushes as, or null when it reported none. */
        public ?string $pushLogin = null,
    ) {
    }
}
