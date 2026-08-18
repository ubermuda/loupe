<?php

declare(strict_types=1);

namespace App\Module\Billing\Diagnostics;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Presence only. Validating the keys would mean calling Stripe from a page an
 * operator opens on a whim, and a self-hosted instance should not make outbound
 * calls nobody asked for.
 */
final readonly class StripeCheck implements DiagnosticInterface
{
    public function __construct(
        private FeatureFlagService $featureFlags,

        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private ?string $stripeSecretKey,

        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        private ?string $stripeWebhookSecret,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 10;
    }

    #[\Override]
    public function __invoke(): ?Diagnostic
    {
        // Stripe is only a requirement of an instance that turned billing on;
        // reporting it otherwise would show a red cross against a feature the
        // operator deliberately left off.
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            return null;
        }

        $missing = [];
        if (null === $this->stripeSecretKey || '' === $this->stripeSecretKey) {
            $missing[] = 'STRIPE_SECRET_KEY';
        }
        if (null === $this->stripeWebhookSecret || '' === $this->stripeWebhookSecret) {
            $missing[] = 'STRIPE_WEBHOOK_SECRET';
        }

        if ([] === $missing) {
            return new Diagnostic('stripe', DiagnosticState::Ok, 'account.system_status.stripe.configured');
        }

        return new Diagnostic(
            'stripe',
            DiagnosticState::Failed,
            'account.system_status.stripe.missing',
            ['%variables%' => implode(', ', $missing)],
        );
    }
}
