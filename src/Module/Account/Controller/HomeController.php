<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'app_home')]
class HomeController extends AppController
{
    public function __construct(
        private readonly ProjectRepository $projects,
    ) {
    }

    public function __invoke(): Response
    {
        // The ^/ firewall guarantees authentication before this runs.
        $user = $this->getUser();
        assert($user instanceof User);

        $projects = $this->projects->findByOwner($user);

        if ([] === $projects && null === $user->wizardCompletedAt) {
            return $this->redirectToRoute('app_welcome');
        }

        if (1 === \count($projects)) {
            return $this->redirectToRoute('app_project_documents', ['id' => (string) $projects[0]->id]);
        }

        return $this->redirectToRoute('app_projects');
    }
}
