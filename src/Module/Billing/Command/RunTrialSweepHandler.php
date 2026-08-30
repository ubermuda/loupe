<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\SubscriptionRepository;
use App\Module\Billing\Service\CancelSurveyEmailSender;
use App\Module\Billing\Service\TrialEndSurveyEmailSender;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The end-of-subscription lifecycle, run hourly. Every action is guarded by a
 * nullable-timestamp marker committed under a lock on the billing profile, so
 * duplicate or missed runs are harmless: a second pass re-selects nothing.
 * Emails are enqueued after the marker commits — delivery failures are the
 * messenger worker's problem (retries, then the failed transport), never a
 * reason to re-send.
 */
final readonly class RunTrialSweepHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TrialEndSurveyEmailSender $trialSurveys,
        private CancelSurveyEmailSender $cancelSurveys,
        private FeatureFlagService $featureFlags,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunTrialSweepCommand $command): TrialSweepResult
    {
        // No paywall, no trial semantics: with billing dark nothing may be
        // disabled or surveyed, whatever the timestamps say.
        if (!$this->featureFlags->isEnabled('billing.enabled')) {
            $this->logger->info('billing.trial_sweep.skipped_billing_disabled');

            return new TrialSweepResult();
        }

        $now = $command->now;
        $disabled = $churned = $subscriber = $cancel = $failed = 0;

        foreach ($this->subscriptions->findEndedTrialsToSurvey($now) as $trial) {
            try {
                [$disabledNow, $churnedNow, $subscriberNow] = $this->endTrial($trial, $now);
                $disabled += $disabledNow;
                $churned += $churnedNow;
                $subscriber += $subscriberNow;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logFailure($trial, $e);
            }
        }

        foreach ($this->subscriptions->findCanceledStripeSubscriptionsToSettle($now) as $subscription) {
            try {
                [$disabledNow, $surveyNow] = $this->settleCanceled($subscription, $now);
                $disabled += $disabledNow;
                $cancel += $surveyNow;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logFailure($subscription, $e);
            }
        }

        return new TrialSweepResult($disabled, $churned, $subscriber, $cancel, $failed);
    }

    /** @return array{int, int, int} [newly disabled, churned surveys marked, subscriber surveys marked] */
    private function endTrial(Subscription $trial, \DateTimeImmutable $now): array
    {
        $profile = $trial->billingProfile;
        $user = $profile->user;

        // DBAL-level transaction, not EntityManager::wrapInTransaction(): a
        // failure there closes the shared EntityManager and would abort every
        // remaining row of the batch.
        [$disabledNow, $churnedNow, $subscriberNow] = $this->em->getConnection()->transactional(function () use ($trial, $profile, $user, $now): array {
            // The profile row is the lock every billing writer takes, so the
            // re-check below sees whatever a racing webhook committed.
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($profile);
            $this->em->refresh($trial);

            if (null === $trial->endsAt || $now < $trial->endsAt || null !== $trial->surveySentAt) {
                $this->logger->debug('billing.trial_sweep.skipped_after_lock', ['userId' => (string) $profile->user->id, 'pass' => 'ended_trials']);

                return [0, 0, 0];
            }

            $trial->surveySentAt = $now;

            if ($profile->hasLiveSubscription()) {
                $this->em->flush();

                return [0, 0, 1];
            }

            // They reached Stripe and left again. The cancellation pass owns
            // that ending and its survey, so this one only marks the trial.
            if (null !== $profile->latestSubscriptionOfKind(SubscriptionKind::Stripe)) {
                $this->em->flush();

                return [0, 0, 0];
            }

            $disabledNow = 0;
            if (null === $user->disabledAt && !$profile->hasCurrentSubscription($now)) {
                $user->disabledAt = $now;
                $disabledNow = 1;
            }
            $this->em->flush();

            return [$disabledNow, 1, 0];
        });

        if (1 === $disabledNow) {
            $this->logger->info('billing.trial_sweep.disabled', ['userId' => (string) $user->id, 'reason' => 'trial_expired']);
        }
        if (1 === $churnedNow && $this->trialSurveys->send($user, subscribed: false)) {
            $this->logger->info('billing.trial_sweep.survey_sent', ['userId' => (string) $user->id, 'variant' => 'churned']);
        }
        if (1 === $subscriberNow && $this->trialSurveys->send($user, subscribed: true)) {
            $this->logger->info('billing.trial_sweep.survey_sent', ['userId' => (string) $user->id, 'variant' => 'subscribed']);
        }

        return [$disabledNow, $churnedNow, $subscriberNow];
    }

    /** @return array{int, int} [newly disabled, cancel surveys marked] */
    private function settleCanceled(Subscription $subscription, \DateTimeImmutable $now): array
    {
        $profile = $subscription->billingProfile;
        $user = $profile->user;

        [$disabledNow, $surveyNow] = $this->em->getConnection()->transactional(function () use ($subscription, $profile, $user, $now): array {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($profile);
            $this->em->refresh($subscription);

            // Any other grant still running — a trial the user has not used up,
            // a comp — keeps the account alive and postpones the survey.
            if (BillingStatus::Canceled !== $subscription->stripeStatus
                || (null !== $subscription->endsAt && $now < $subscription->endsAt)
                || $profile->hasCurrentSubscription($now)) {
                $this->logger->debug('billing.trial_sweep.skipped_after_lock', ['userId' => (string) $user->id, 'pass' => 'canceled_stripe']);

                return [0, 0];
            }

            $disabledNow = 0;
            if (null === $user->disabledAt) {
                $user->disabledAt = $now;
                $disabledNow = 1;
            }

            $surveyNow = 0;
            if (null === $subscription->surveySentAt) {
                $subscription->surveySentAt = $now;
                $surveyNow = 1;
            }

            if ($disabledNow || $surveyNow) {
                $this->em->flush();
            }

            return [$disabledNow, $surveyNow];
        });

        if (1 === $disabledNow) {
            $this->logger->info('billing.trial_sweep.disabled', ['userId' => (string) $user->id, 'reason' => 'subscription_canceled']);
        }
        if (1 === $surveyNow && $this->cancelSurveys->send($user)) {
            $this->logger->info('billing.trial_sweep.cancel_survey_sent', ['userId' => (string) $user->id]);
        }

        return [$disabledNow, $surveyNow];
    }

    private function logFailure(Subscription $subscription, \Throwable $e): void
    {
        $this->logger->error('billing.trial_sweep.row_failed', [
            'subscriptionId' => (string) $subscription->id,
            'userId' => (string) $subscription->billingProfile->user->id,
            'error' => $e->getMessage(),
        ]);
    }
}
