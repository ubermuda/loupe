<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\SetDefaultBoardColumnCommand;
use App\Module\Board\Command\SetDefaultBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Form\SetDefaultBoardColumnFormType;
use App\Module\Board\Form\SetDefaultBoardColumnRequest;
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
    '/projects/{projectId}/board/columns/{columnId}/default',
    name: 'app_board_column_default',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SetDefaultBoardColumnController extends AppController
{
    public function __construct(
        private readonly SetDefaultBoardColumnHandler $setDefault,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        $data = new SetDefaultBoardColumnRequest();
        $form = $this->formFactory->createNamed(SetDefaultBoardColumnFormType::nameFor($column), SetDefaultBoardColumnFormType::class, $data);
        $form->handleRequest($request);
        $refusal = SetDefaultBoardColumnHandler::STALE;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->setDefault)(new SetDefaultBoardColumnCommand($column, $data->expectedDefaultId ?? ''));
                $refusal = null;
            } catch (DomainErrors $e) {
                $refusal = array_first($e->errors);
            }
        }
        if (null !== $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal));
        }

        return $this->redirectToRoute('settings' === $request->query->get('view') ? 'app_board_settings' : 'app_project_board', ['id' => (string) $column->project->id]);
    }
}
