<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Forge;

/** What a parser could read out of a pull request URL. */
final readonly class ForgeRef
{
    public function __construct(
        public Forge $forge,
        public ?string $repository = null,
        public ?int $number = null,
    ) {
    }

    /** The answer for a URL no parser recognises, which is a legitimate answer. */
    public static function unknown(): self
    {
        return new self(Forge::Other);
    }
}
