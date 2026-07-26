<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\CancelSurveyEmailSender;
use App\Module\Billing\Service\TrialEndSurveyEmailSender;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The end-of-subscription lifecycle, run hourly. Every action is guarded by a
 * nullable-timestamp marker committed under a row lock, so duplicate or missed
 * runs are harmless: a second pass re-selects nothing. Emails are enqueued
 * after the marker commits — delivery failures are the messenger worker's
 * problem (retries, then the failed transport), never a reason to re-send.
 */
final readonly class RunTrialSweepHandler
{
    public function __construct(
        private BillingProfileRepository $billingProfiles,
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

        foreach ($this->billingProfiles->findExpiredTrials($now) as $profile) {
            try {
                [$disabledNow, $churnedNow] = $this->endExpiredTrial($profile, $now);
                $disabled += $disabledNow;
                $churned += $churnedNow;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logFailure($profile, $e);
            }
        }

        foreach ($this->billingProfiles->findTrialEndedSubscribers($now) as $profile) {
            try {
                if ($this->surveySubscriber($profile, $now)) {
                    ++$subscriber;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->logFailure($profile, $e);
            }
        }

        foreach ($this->billingProfiles->findCanceledPastPeriod($now) as $profile) {
            try {
                [$disabledNow, $surveyNow] = $this->settleCanceled($profile, $now);
                $disabled += $disabledNow;
                $cancel += $surveyNow;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logFailure($profile, $e);
            }
        }

        return new TrialSweepResult($disabled, $churned, $subscriber, $cancel, $failed);
    }

    /** @return array{int, int} [newly disabled, churned surveys marked] */
    private function endExpiredTrial(BillingProfile $profile, \DateTimeImmutable $now): array
    {
        // DBAL-level transaction, not EntityManager::wrapInTransaction(): a
        // failure there closes the shared EntityManager and would abort every
        // remaining row of the batch.
        [$disabledNow, $churnedNow] = $this->em->getConnection()->transactional(function () use ($profile, $now): array {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($profile);

            // Re-check under the lock: the Stripe webhook may have activated
            // this subscription between the candidate query and here.
            if (BillingStatus::Trialing !== $profile->status || $now < $profile->trialEndsAt || null !== $profile->surveySentAt) {
                $this->logger->debug('billing.trial_sweep.skipped_after_lock', ['userId' => (string) $profile->user->id, 'pass' => 'expired_trials']);

                return [0, 0];
            }

            $disabledNow = 0;
            if (null === $profile->user->disabledAt) {
                $profile->user->disabledAt = $now;
                $disabledNow = 1;
            }
            $profile->surveySentAt = $now;
            $this->em->flush();

            return [$disabledNow, 1];
        });

        if (0 === $churnedNow) {
            return [0, 0];
        }

        if (1 === $disabledNow) {
            $this->logger->info('billing.trial_sweep.disabled', ['userId' => (string) $profile->user->id, 'reason' => 'trial_expired']);
        }
        if ($this->trialSurveys->send($profile->user, subscribed: false)) {
            $this->logger->info('billing.trial_sweep.survey_sent', ['userId' => (string) $profile->user->id, 'variant' => 'churned']);
        }

        return [$disabledNow, $churnedNow];
    }

    private function surveySubscriber(BillingProfile $profile, \DateTimeImmutable $now): bool
    {
        $acted = $this->em->getConnection()->transactional(function () use ($profile, $now): bool {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($profile);

            if (!in_array($profile->status, [BillingStatus::Active, BillingStatus::PastDue], true)
                || $now < $profile->trialEndsAt
                || null !== $profile->surveySentAt) {
                $this->logger->debug('billing.trial_sweep.skipped_after_lock', ['userId' => (string) $profile->user->id, 'pass' => 'trial_ended_subscribers']);

                return false;
            }

            $profile->surveySentAt = $now;
            $this->em->flush();

            return true;
        });

        if (!$acted) {
            return false;
        }

        if ($this->trialSurveys->send($profile->user, subscribed: true)) {
            $this->logger->info('billing.trial_sweep.survey_sent', ['userId' => (string) $profile->user->id, 'variant' => 'subscribed']);
        }

        return true;
    }

    /** @return array{int, int} [newly disabled, cancel surveys marked] */
    private function settleCanceled(BillingProfile $profile, \DateTimeImmutable $now): array
    {
        [$disabledNow, $surveyNow] = $this->em->getConnection()->transactional(function () use ($profile, $now): array {
            $this->em->lock($profile, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($profile);

            if (BillingStatus::Canceled !== $profile->status
                || (null !== $profile->currentPeriodEnd && $now < $profile->currentPeriodEnd)) {
                $this->logger->debug('billing.trial_sweep.skipped_after_lock', ['userId' => (string) $profile->user->id, 'pass' => 'canceled_past_period']);

                return [0, 0];
            }

            $disabledNow = 0;
            if (null === $profile->user->disabledAt) {
                $profile->user->disabledAt = $now;
                $disabledNow = 1;
            }

            $surveyNow = 0;
            if (null === $profile->cancelSurveySentAt) {
                $profile->cancelSurveySentAt = $now;
                $surveyNow = 1;
            }

            if ($disabledNow || $surveyNow) {
                $this->em->flush();
            }

            return [$disabledNow, $surveyNow];
        });

        if (1 === $disabledNow) {
            $this->logger->info('billing.trial_sweep.disabled', ['userId' => (string) $profile->user->id, 'reason' => 'subscription_canceled']);
        }
        if (1 === $surveyNow && $this->cancelSurveys->send($profile->user)) {
            $this->logger->info('billing.trial_sweep.cancel_survey_sent', ['userId' => (string) $profile->user->id]);
        }

        return [$disabledNow, $surveyNow];
    }

    private function logFailure(BillingProfile $profile, \Throwable $e): void
    {
        $this->logger->error('billing.trial_sweep.row_failed', [
            'profileId' => (string) $profile->id,
            'userId' => (string) $profile->user->id,
            'error' => $e->getMessage(),
        ]);
    }
}
