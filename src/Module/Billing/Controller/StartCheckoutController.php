<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\StartCheckoutCommand;
use App\Module\Billing\Command\StartCheckoutHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-checkout')]
#[Route(
    '/billing/checkout',
    name: 'app_billing_checkout',
    methods: ['POST'],
)]
final class StartCheckoutController extends AppController
{
    public function __construct(
        private readonly StartCheckoutHandler $startCheckout,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        // Stripe rejects a Checkout session without both URLs, so an empty one
        // would fail deep inside the API call rather than here.
        $successUrl = $this->generateUrl('app_billing_checkout_success', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('app_billing_subscribe', referenceType: UrlGeneratorInterface::ABSOLUTE_URL);
        if ('' === $successUrl || '' === $cancelUrl) {
            throw new \LogicException('Checkout return URLs could not be generated');
        }

        try {
            $url = ($this->startCheckout)(new StartCheckoutCommand(
                user: $user,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                inviteToken: $request->request->getString('invite') ?: null,
            ));
        } catch (DomainErrors $e) {
            // There is no form to attach field errors to — the page is a button.
            // A full cap deserves its own message: the fix is the waitlist, not
            // "try again later".
            $flashKey = 'billing.error.capacity_full' === ($e->errors['billing'] ?? null)
                ? 'billing.flash.capacity_full'
                : 'billing.flash.checkout_unavailable';
            $this->addFlash('error', $this->translator->trans($flashKey));

            return $this->redirectToRoute('app_billing_subscribe');
        }

        return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
    }
}
