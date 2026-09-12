<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Mcp\AdvertisedTools;

final readonly class ListAdvertisedToolsHandler
{
    public function __construct(
        private AdvertisedTools $advertisedTools,
    ) {
    }

    public function __invoke(ListAdvertisedToolsCommand $command): ListAdvertisedToolsView
    {
        return new ListAdvertisedToolsView($this->advertisedTools->enabled());
    }
}
