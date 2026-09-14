<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Inbox\Command\ShowInboxOpenCountCommand;
use App\Module\Inbox\Command\ShowInboxOpenCountHandler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The sidebar pill alone, for the frame the inbox-pill controller reloads. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/inbox/open-count',
    name: 'app_project_inbox_open_count',
    methods: ['GET'],
)]
final class ShowInboxOpenCountController extends AppController
{
    public function __construct(
        private readonly ShowInboxOpenCountHandler $showOpenCount,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $this->inbox->requireEnabled();

        return $this->render('@Inbox/_open_count.html.twig', [
            'project' => $project,
            'openCount' => ($this->showOpenCount)(new ShowInboxOpenCountCommand($project)),
        ]);
    }
}
