<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Service\ReadinessChecklist;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken(ReadinessChecklist::START_TOKEN)]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/readiness/discovery/start',
    name: 'app_readiness_discovery_start',
    methods: ['POST'],
)]
final class StartDiscoveryController extends AppController
{
    public function __construct(
        private readonly StartDiscoveryHandler $startDiscovery,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            ($this->startDiscovery)(new StartDiscoveryCommand($project, CardReporter::Human));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));
        } catch (DiscoveryRunning $e) {
            $this->addFlash('error', $this->translator->trans(DiscoveryRunning::MESSAGE, ['%number%' => $e->cardNumber]));
        }

        return $this->redirectToRoute('app_project_workshop', ['id' => (string) $project->id]);
    }
}
