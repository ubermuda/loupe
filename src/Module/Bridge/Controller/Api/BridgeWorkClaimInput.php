<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One work request a bridge holds, and the claim token that proves it. */
final class BridgeWorkClaimInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $id = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $claimToken = null,
    ) {
    }
}
