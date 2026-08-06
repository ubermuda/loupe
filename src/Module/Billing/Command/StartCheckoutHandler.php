<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Service\ActivePriceProvider;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\TrialProvisioner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final readonly class StartCheckoutHandler
{
    public function __construct(
        private TrialProvisioner $trialProvisioner,
        private ActivePriceProvider $prices,
        private StripeGatewayInterface $stripe,
        private FeatureFlagService $featureFlags,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private RegistrationGate $registrationGate,
        private WaitlistEntryRepository $waitlistEntries,
    ) {
    }

    /** @return string the hosted Checkout URL */
    public function __invoke(StartCheckoutCommand $command): string
    {
        // The master switch gates the actions too, not just the paywall — a
        // direct POST while billing is dark must not create Stripe state.
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            throw new DomainErrors(['billing' => 'billing.error.disabled']);
        }

        $priceId = $this->prices->activePriceId();
        if (null === $priceId) {
            throw new DomainErrors(['price' => 'billing.error.no_active_price']);
        }

        $profile = $this->trialProvisioner->ensureProfile($command->user);

        // A snapshot check, deliberately without the capacity advisory lock: it
        // is transaction-scoped and could not span the Stripe call anyway, and a
        // lost race means slight over-cap, which the unconditional webhook
        // re-enable accepts.
        if ($command->user->isDisabled()
            && !$this->registrationGate->isOpen()
            && !$this->hasValidInvite($command)) {
            throw new DomainErrors(['billing' => 'billing.error.capacity_full']);
        }

        try {
            // Resolving the customer happens under a write lock on the profile
            // row. Two concurrent submits — a double-click, a retried redirect,
            // two tabs — would otherwise both see no Stripe customer and create
            // two of them, which would also give them different idempotency keys
            // and so two Checkout sessions that each bill the user.
            $customerId = $this->em->wrapInTransaction(function () use ($command, $profile): string {
                $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
                // lock() takes the row but does not refresh what is in memory;
                // without this the winner's customer id would go unseen.
                $this->em->refresh($profile);

                // Never open a second Checkout for a customer Stripe already
                // holds a subscription for. Whatever is wrong with the existing
                // one (unpaid, card expired) is fixed in the portal.
                if ($profile->hasLiveSubscription()) {
                    throw new DomainErrors(['billing' => 'billing.error.subscription_exists']);
                }

                $customerId = $profile->stripeCustomerId;
                if (null === $customerId) {
                    $customerId = $this->stripe->createCustomer($command->user);
                    $profile->stripeCustomerId = $customerId;
                    $this->em->flush();
                }

                return $customerId;
            });

            // Outside the transaction deliberately: a Checkout failure must not
            // roll back the customer id just committed, or the retry mints a
            // second Stripe customer. Both racers now read the same committed
            // customer, so both compute the same idempotency key.
            $url = $this->stripe->createCheckoutSession(
                $customerId,
                $priceId,
                $command->successUrl,
                $command->cancelUrl,
                $this->idempotencyKey($profile, $priceId),
            );
        } catch (DomainErrors $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Stripe being down or rejecting the call is a bad minute, not a
            // bug: surface it as a domain failure so the user gets the "try
            // again later" page instead of a 500.
            $this->logger->error('billing.checkout.stripe_failed', [
                'userId' => (string) $command->user->id,
                'error' => $e->getMessage(),
            ]);

            throw new DomainErrors(['billing' => 'billing.error.stripe_unavailable']);
        }

        $this->logger->info('billing.checkout.started', ['userId' => (string) $command->user->id, 'priceId' => $priceId]);

        return $url;
    }

    /**
     * Identifies one checkout attempt to Stripe. A repeated key replays the
     * session already created — what a double submit needs — while a genuine
     * re-subscription after a cancellation carries a different last-event id and
     * so gets a session of its own. The price is part of the key too, so a price
     * change is never sold at yesterday's session.
     */
    private function idempotencyKey(BillingProfile $profile, string $priceId): string
    {
        return sprintf('checkout_%s_%s_%s', (string) $profile->id, $priceId, $profile->lastStripeEventId ?? 'initial');
    }

    /**
     * A token is only a capacity voucher for the address it was issued to —
     * possession alone (a forwarded or leaked link) must not let a different
     * account claim it.
     */
    private function hasValidInvite(StartCheckoutCommand $command): bool
    {
        if (null === $command->inviteToken) {
            return false;
        }

        // findOneByValidInviteToken already validated the token; no
        // lock/refresh here to invalidate that.
        $invite = $this->waitlistEntries->findOneByValidInviteToken($command->inviteToken);

        return null !== $invite && $invite->isInviteFor($command->user->email);
    }
}
