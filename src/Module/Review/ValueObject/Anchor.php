<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class Anchor
{
    public function __construct(
        #[ORM\Column(length: 255)]
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
