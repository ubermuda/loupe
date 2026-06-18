<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class Anchor
{
    public function __construct(
        // The quote is a verbatim excerpt of the document and can be sentence- or
        // paragraph-length, so it must not be capped at VARCHAR(255).
        #[ORM\Column(type: Types::TEXT)]
        public string $quote,

        #[ORM\Column(length: 255)]
        public string $prefix,

        #[ORM\Column(length: 255)]
        public string $suffix,

        #[ORM\Column]
        public int $offsetHint,
    ) {
    }
}
