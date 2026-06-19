<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/documents',
    name: 'app_documents',
    methods: ['GET'],
)]
class DocumentDashboardController extends AppController
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $documents = $this->documents->findBy(['owner' => $user], ['createdAt' => 'DESC']);

        return $this->render('review/dashboard.html.twig', [
            'documents' => $documents,
        ]);
    }
}
