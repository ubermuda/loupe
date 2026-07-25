<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\StartCheckoutCommand;
use App\Module\Billing\Command\StartCheckoutHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-checkout')]
#[Route('/billing/checkout', name: 'app_billing_checkout', methods: ['POST'])]
final class StartCheckoutController extends AppController
{
    public function __construct(
        private readonly StartCheckoutHandler $startCheckout,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        try {
            $url = ($this->startCheckout)(new StartCheckoutCommand(
                user: $user,
                successUrl: $this->generateUrl('app_billing_checkout_success', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
                cancelUrl: $this->generateUrl('app_billing_subscribe', referenceType: UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        } catch (DomainErrors) {
            // There is no form to attach field errors to — the page is a button.
            $this->addFlash('error', $this->translator->trans('billing.flash.checkout_unavailable'));

            return $this->redirectToRoute('app_billing_subscribe');
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
