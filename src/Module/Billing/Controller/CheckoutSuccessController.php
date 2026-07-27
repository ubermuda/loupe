<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where Stripe returns the browser after a completed Checkout. It renders a
 * confirmation page rather than redirecting into the app: the subscription
 * webhook may not have landed yet, and a redirect would bounce a user whose
 * trial has expired straight back to the paywall. The page is never treated as
 * proof of payment — only the webhook writes subscription state.
 */
#[PaywallExempt]
#[Route(
    '/billing/checkout/success',
    name: 'app_billing_checkout_success',
    methods: ['GET'],
)]
final class CheckoutSuccessController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Billing/checkout_success.html.twig');
    }
}
