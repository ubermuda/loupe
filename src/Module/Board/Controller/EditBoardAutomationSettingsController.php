<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Form\SaveBoardAutomationSettingsFormType;
use App\Module\Board\Form\SaveBoardAutomationSettingsRequest;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/automation',
    name: 'app_board_automation_settings',
    methods: ['GET', 'POST'],
)]
final class EditBoardAutomationSettingsController extends AppController
{
    public function __construct(
        private readonly BoardAutomation $automation,
        private readonly SaveBoardAutomationSettingsHandler $saveSettings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = SaveBoardAutomationSettingsRequest::fromSettings($this->automation->settingsOf($project));
        $form = $this->createForm(SaveBoardAutomationSettingsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->saveSettings)(new SaveBoardAutomationSettingsCommand(
                    project: $project,
                    enabled: $data->enabled,
                    stuckDelayMinutes: $data->stuckDelayMinutes ?? throw new \LogicException('stuck delay required after validation'),
                ));
                $this->addFlash('success', $this->translator->trans('board.automation.flash.saved'));

                return $this->redirectToRoute('app_board_automation_settings', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->renderFormResponse('@Board/edit_board_automation_settings.html.twig', $form, [
            'project' => $project,
            'failedComment' => $this->automation->newestFailedComment($project),
        ]);
    }
}
