<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('worker-run-command')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/worker-runs/{runId}/stop',
    name: 'app_project_worker_run_stop',
    requirements: ['runId' => Requirement::UUID],
    methods: ['POST'],
)]
final class StopWorkerRunController extends AppController
{
    public function __construct(
        private readonly RequestBridgeCommandHandler $requestCommand,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(runId, project)')] WorkerRun $run,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The project voter admits only a signed-in user.');
        }

        try {
            ($this->requestCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::StopRun, $user));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('worker-run-command', $this->translator->trans($translationKey));
            }
        }

        if ('card-worker-runs' === $request->headers->get('Turbo-Frame')) {
            return $this->redirectToRoute('app_project_card_worker_runs', [
                'id' => (string) $project->id,
                'cardId' => (string) $run->cardId,
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_project_worker_runs', [
            'id' => (string) $project->id,
            'search' => (string) $run->id,
        ], Response::HTTP_SEE_OTHER);
    }
}
