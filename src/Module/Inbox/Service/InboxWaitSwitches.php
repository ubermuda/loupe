<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Module\Project\Entity\Project;

final readonly class InboxWaitSwitches
{
    public function __construct(
        private InboxProjectSettingsRepository $inboxProjectSettings,
    ) {
    }

    /** The stored settings, or unsaved all-on settings, so an upgraded instance needs no data migration. */
    public function for(Project $project): InboxProjectSettings
    {
        return $this->inboxProjectSettings->findForProject($project) ?? new InboxProjectSettings($project);
    }
}
