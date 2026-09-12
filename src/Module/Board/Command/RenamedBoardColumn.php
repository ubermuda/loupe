<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** The slug a rename replaced, and the one it wrote. */
final readonly class RenamedBoardColumn
{
    public function __construct(
        public string $fromSlug,
        public string $toSlug,
    ) {
    }
}
