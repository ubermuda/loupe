<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Inbox\Command\UpdateInboxSettingsCommand;
use App\Module\Inbox\Command\UpdateInboxSettingsHandler;
use App\Module\Inbox\Form\UpdateInboxSettingsFormType;
use App\Module\Inbox\Form\UpdateInboxSettingsRequest;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxWaitSwitches;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/inbox/settings',
    name: 'app_inbox_settings',
    methods: ['GET', 'POST'],
)]
final class EditInboxSettingsController extends AppController
{
    public function __construct(
        private readonly InboxAvailability $inbox,
        private readonly InboxWaitSwitches $switches,
        private readonly UpdateInboxSettingsHandler $updateSettings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->inbox->requireEnabled();

        $data = UpdateInboxSettingsRequest::fromSettings($this->switches->for($project));
        $form = $this->createForm(UpdateInboxSettingsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->updateSettings)(new UpdateInboxSettingsCommand(
                project: $project,
                documentInReview: $data->documentInReview,
                runBlocked: $data->runBlocked,
                runGaveUp: $data->runGaveUp,
                runWaitingForPerson: $data->runWaitingForPerson,
                pullRequestReady: $data->pullRequestReady,
                pullRequestFixStopped: $data->pullRequestFixStopped,
                cardPaused: $data->cardPaused,
            ));
            $this->addFlash('success', $this->translator->trans('inbox.settings.flash.saved'));

            return $this->redirectToRoute('app_inbox_settings', ['id' => (string) $project->id]);
        }

        return $this->renderFormResponse('@Inbox/edit_inbox_settings.html.twig', $form, [
            'project' => $project,
        ]);
    }
}
