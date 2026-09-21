<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Bridge\Service\BridgeLiveness;
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
        private BridgeLiveness $bridgeLiveness,
    ) {
    }

    public function __invoke(ShowAccountInboxCommand $command): AccountInboxDetailView
    {
        $projects = [];
        $asks = [];
        $bridgeIds = [];
        foreach ($this->inboxAsks->findOpenByOwner($command->owner) as $ask) {
            $key = (string) $ask->project->id;
            $projects[$key] = $ask->project;
            $asks[$key][] = $ask;
            if (null !== $ask->bridgeId) {
                $bridgeIds[$ask->bridgeId->toRfc4122()] = $ask->bridgeId;
            }
        }

        $looseItems = [];
        foreach ($this->inboxItems->findOpenOutsideOpenAsksByOwner($command->owner) as $item) {
            $key = (string) $item->project->id;
            $projects[$key] = $item->project;
            $looseItems[$key][] = $item;
        }

        uasort($projects, static fn (Project $a, Project $b): int => [mb_strtolower($a->name), (string) $a->id] <=> [mb_strtolower($b->name), (string) $b->id]);
        $counts = $this->inboxItems->countOpenByProjects(array_values($projects));

        $groups = [];
        foreach ($projects as $key => $project) {
            $groups[] = new AccountInboxProjectGroup($project, $asks[$key] ?? [], $looseItems[$key] ?? [], $counts[$key] ?? 0);
        }

        return new AccountInboxDetailView($groups, $this->bridgeLiveness->forOwner($command->owner, array_values($bridgeIds)));
    }
}
