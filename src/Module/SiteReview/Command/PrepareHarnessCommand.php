<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

final readonly class PrepareHarnessCommand
{
    public function __construct(
        public string $email,
        public bool $keepComments,
        /** The harness page's own origin, added to the allowed sites so the OAuth embed can sign in. */
        public ?string $oauthOrigin = null,
    ) {
    }
}
