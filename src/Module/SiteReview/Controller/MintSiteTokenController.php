<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\MintSiteTokenCommand;
use App\Module\SiteReview\Command\MintSiteTokenHandler;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Security\SiteVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('mint-site-token')]
#[IsGranted(SiteVoter::MANAGE, subject: 'site')]
#[Route(
    '/site-review/sites/{id:site}/token',
    name: 'app_site_review_site_token_mint',
    methods: ['POST'],
)]
class MintSiteTokenController extends AppController
{
    public function __construct(
        private readonly MintSiteTokenHandler $mintSiteTokenHandler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Site $site): Response
    {
        try {
            $raw = ($this->mintSiteTokenHandler)(new MintSiteTokenCommand($site));
            $this->addFlash('success', sprintf(
                '%s %s',
                $this->translator->trans('site_review.site.token.flash_minted'),
                $raw,
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_site_review_site', ['id' => (string) $site->id]);
    }
}
