<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\OpenPortalCommand;
use App\Module\Billing\Command\OpenPortalHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-portal')]
#[Route(
    '/billing/portal',
    name: 'app_billing_portal',
    methods: ['POST'],
)]
final class OpenPortalController extends AppController
{
    public function __construct(
        private readonly OpenPortalHandler $openPortal,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        // Stripe rejects a portal session without a return URL, so an empty one
        // would fail deep inside the API call rather than here.
        $returnUrl = $this->generateUrl('app_billing_subscribe', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        if ('' === $returnUrl) {
            throw new \LogicException('Portal return URL could not be generated');
        }

        try {
            $url = ($this->openPortal)(new OpenPortalCommand(
                user: $user,
                returnUrl: $returnUrl,
            ));
        } catch (DomainErrors) {
            $this->addFlash('error', $this->translator->trans('billing.flash.portal_unavailable'));

            return $this->redirectToRoute('app_billing_subscribe');
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
