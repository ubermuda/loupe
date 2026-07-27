<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\CreateProjectCommand;
use App\Module\Project\Command\CreateProjectHandler;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\View\ProjectListItem;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/projects',
    name: 'app_project_create',
    methods: ['POST'],
)]
class CreateProjectController extends AppController
{
    public function __construct(
        private readonly CreateProjectHandler $createProjectHandler,
        private readonly ProjectRepository $projects,
        private readonly DocumentRepository $documents,
        private readonly SiteReviewRepository $siteReviews,
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        $data = new CreateProjectRequest();
        $form = $this->createForm(CreateProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->createProjectHandler)(new CreateProjectCommand(
                    owner: $user,
                    name: trim($data->name ?? '') ?: throw new \LogicException('name required after validation'),
                    domain: trim($data->domain ?? '') ?: null,
                ));

                return $this->redirectToRoute('app_projects');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        $items = array_map(
            fn ($project) => new ProjectListItem(
                project: $project,
                documentCount: $this->documents->countByProject($project),
                reviewCount: $this->siteReviews->countForProject($project),
                openCount: $this->siteReviewComments->countOpenForProject($project),
            ),
            $this->projects->findByOwner($user),
        );

        return $this->renderFormResponse('@Project/list_projects.html.twig', $form, [
            'items' => $items,
        ]);
    }
}
