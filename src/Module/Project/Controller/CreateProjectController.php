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
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use App\Utils\PageList;
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
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly CreateProjectHandler $createProjectHandler,
        private readonly ProjectRepository $projects,
        private readonly DocumentRepository $documents,
        private readonly SiteReviewEventRepository $siteReviewEvents,
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
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

        $page = max(1, $request->query->getInt('page', 1));
        $paginator = $this->projects->findPaginatedByOwner($user, $page, self::PER_PAGE);
        $totalPages = max(1, (int) ceil(count($paginator) / self::PER_PAGE));

        $items = array_map(
            fn ($project) => new ProjectListItem(
                project: $project,
                documentCount: $this->documents->countActiveByProject($project),
                reviewCount: $this->siteReviewEvents->countForProject($project),
                openCount: $this->siteReviewComments->countOpenForProject($project),
            ),
            iterator_to_array($paginator, false),
        );

        return $this->renderFormResponse('@Project/list_projects.html.twig', $form, [
            'items' => $items,
            'page' => $page,
            'totalPages' => $totalPages,
            'pageList' => PageList::build($page, $totalPages),
        ]);
    }
}
