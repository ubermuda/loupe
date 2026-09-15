<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Inbox\Command\ShowInboxCommand;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/inbox',
    name: 'app_project_inbox',
    methods: ['GET'],
)]
final class ShowInboxController extends AppController
{
    /** The request attribute a refused answer, done or decline form is forwarded under. */
    public const string REFUSED_FORM = 'refusedForm';

    public function __construct(
        private readonly ShowInboxHandler $showInbox,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->inbox->requireEnabled();

        return $this->render('@Inbox/show_inbox.html.twig', [
            'inbox' => ($this->showInbox)(new ShowInboxCommand($project, $request->query->getInt('page', 1))),
            'refusedForm' => $this->getInjectedFormView($request, self::REFUSED_FORM),
        ]);
    }
}
