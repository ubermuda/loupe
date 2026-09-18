<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ConfigureBoardColumnCommand;
use App\Module\Board\Command\ConfigureBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Form\ConfigureBoardColumnFormType;
use App\Module\Board\Form\ConfigureBoardColumnRequest;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/configure',
    name: 'app_board_column_configure',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ConfigureBoardColumnController extends AppController
{
    public function __construct(
        private readonly ConfigureBoardColumnHandler $configureColumn,
        private readonly FormFactoryInterface $formFactory,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        $project = $column->project;
        $data = new ConfigureBoardColumnRequest();
        $form = $this->formFactory->createNamed(ConfigureBoardColumnFormType::nameFor($column), ConfigureBoardColumnFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->configureColumn)(new ConfigureBoardColumnCommand(
                    column: $column,
                    actor: CardReporter::Human,
                    label: $data->label ?? '',
                    isDefault: $data->isDefault,
                    terminal: $data->terminal,
                    expectedLabel: $data->expectedLabel ?? '',
                    expectedDefaultId: $data->expectedDefaultId ?? '',
                    expectedTerminal: '1' === $data->expectedTerminal,
                ));

                return $this->redirectToRoute('app_board_settings', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                // The forwarded page has no dialog for a column that went away.
                if (isset($e->errors['column'])) {
                    $this->addFlash('error', $this->translator->trans($e->errors['column']));

                    return $this->redirectToRoute('app_board_settings', ['id' => (string) $project->id]);
                }
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ShowBoardController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'configureColumnForm' => $form->createView(),
            'boardSettings' => true,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
