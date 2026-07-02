<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Command\CreateSiteCommand;
use App\Module\SiteReview\Command\CreateSiteHandler;
use App\Module\SiteReview\Form\CreateSiteFormType;
use App\Module\SiteReview\Form\CreateSiteRequest;
use App\Module\SiteReview\Repository\SiteRepository;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(
    '/site-review/sites',
    name: 'app_site_review_site_create',
    methods: ['POST'],
)]
class CreateSiteController extends AppController
{
    public function __construct(
        private readonly CreateSiteHandler $createSiteHandler,
        private readonly SiteRepository $sites,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $data = new CreateSiteRequest();
        $form = $this->createForm(CreateSiteFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->createSiteHandler)(new CreateSiteCommand(
                    owner: $user,
                    name: trim($data->name ?? '') ?: throw new \LogicException('name required after validation'),
                ));

                return $this->redirectToRoute('app_site_review_sites');
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->renderFormResponse('@SiteReview/sites/list_sites.html.twig', $form, [
            'sites' => $this->sites->findByOwner($user),
        ]);
    }
}
