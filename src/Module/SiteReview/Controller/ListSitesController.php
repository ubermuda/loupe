<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Form\CreateSiteFormType;
use App\Module\SiteReview\Form\CreateSiteRequest;
use App\Module\SiteReview\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/site-review/sites',
    name: 'app_site_review_sites',
    methods: ['GET'],
)]
class ListSitesController extends AppController
{
    public function __construct(
        private readonly SiteRepository $sites,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $form = $this->createForm(CreateSiteFormType::class, new CreateSiteRequest());

        return $this->render('@SiteReview/sites/list_sites.html.twig', [
            'sites' => $this->sites->findByOwner($user),
            'form' => $form->createView(),
        ]);
    }
}
