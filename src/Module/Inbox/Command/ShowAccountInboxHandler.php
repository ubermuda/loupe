<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\View\AccountInboxDetailView;
use App\Module\Inbox\View\AccountInboxProjectGroup;
use App\Module\Project\Entity\Project;

final readonly class ShowAccountInboxHandler
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
    ) {
    }

    public function __invoke(ShowAccountInboxCommand $command): AccountInboxDetailView
    {
        /** @var array<string, Project> $projects */
        $projects = [];
        /** @var array<string, list<InboxAsk>> $asks */
        $asks = [];
        foreach ($this->inboxAsks->findOpenByOwner($command->owner) as $ask) {
            $key = (string) $ask->project->id;
            $projects[$key] = $ask->project;
            $asks[$key][] = $ask;
        }

        $counts = $this->inboxItems->countOpenByProjects(array_values($projects));

        $groups = [];
        foreach ($projects as $key => $project) {
            $groups[] = new AccountInboxProjectGroup($project, $asks[$key], $counts[$key] ?? 0);
        }

        return new AccountInboxDetailView($command->owner, $groups);
    }
}
