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
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

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
