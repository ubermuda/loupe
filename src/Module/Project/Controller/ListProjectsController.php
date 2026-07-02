<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/site-review/sites',
    name: 'app_site_review_sites',
    methods: ['GET'],
)]
class ListProjectsController extends AppController
{
    public function __construct(
        private readonly ProjectRepository $projects,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $form = $this->createForm(CreateProjectFormType::class, new CreateProjectRequest());

        return $this->render('@Project/list_projects.html.twig', [
            'projects' => $this->projects->findByOwner($user),
            'form' => $form->createView(),
        ]);
    }
}
