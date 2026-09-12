<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BuildIdentity;
use App\Service\UpdateCheck;

final readonly class ShowAboutHandler
{
    public function __construct(
        private BuildIdentity $build,
        private UpdateCheck $updateCheck,
    ) {
    }

    public function __invoke(ShowAboutCommand $command): ShowAboutView
    {
        // Gated on the caller, not just hidden in the template: an anonymous hit
        // would otherwise spend this instance's GitHub rate limit for a card
        // nobody is shown.
        if (!$command->signedIn) {
            return new ShowAboutView(null, null);
        }

        return new ShowAboutView($this->build->version, $this->updateCheck->status());
    }
}
