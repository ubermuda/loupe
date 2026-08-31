<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Billing\Command\RunTrialSweepCommand;
use App\Module\Billing\Command\RunTrialSweepHandler;
use App\Module\Billing\Command\TrialSweepResult;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\SubscriptionRepository;
use App\Module\Billing\Service\CancelSurveyEmailSender;
use App\Module\Billing\Service\TrialEndSurveyEmailSender;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The row locking (PESSIMISTIC_WRITE + refresh + re-check) needs the real
 * database, hence KernelTestCase. The concurrent race itself cannot be
 * exercised here — dama wraps each test in a single connection's transaction,
 * so two overlapping database transactions are not expressible. The marker
 * idempotence tests (a second sweep changes nothing) are the sequential
 * regression guard for that per-row shape.
 */
final class RunTrialSweepHandlerTest extends KernelTestCase
{
    private const array FLAGS = [
        'billing.enabled' => true,
        TrialEndSurveyEmailSender::URL_FLAG_CHURNED => 'https://survey.example.com/churned',
        TrialEndSurveyEmailSender::URL_FLAG_SUBSCRIBED => 'https://survey.example.com/subscribed',
        CancelSurveyEmailSender::URL_FLAG => 'https://survey.example.com/canceled',
    ];

    private RecordingMailer $mailer;

    private RecordingAuditor $audit;

    private RecordingLogger $logger;

    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->logger = new RecordingLogger();
        // Wall-clock, not a fixed date: the grant fixtures are built from
        // relative offsets, so a frozen "now" would put them all in the future.
        $this->now = new \DateTimeImmutable();
    }

    public function test_expired_trial_disables_the_user_and_sends_the_churned_survey(): void
    {
        $profile = $this->seedProfile('churnone');

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(disabled: 1, churnedSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $this->trial($profile)->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/trial_end_survey_churned.html.twig', $email->getHtmlTemplate());
        self::assertSame('churnone@example.com', $email->getTo()[0]->getAddress());
    }

    public function test_expired_trial_of_an_already_disabled_user_counts_no_disable(): void
    {
        // An admin may have disabled the account before the trial ran out. The
        // survey still counts as processed, but the disabled count reports
        // only rows the sweep actually disabled.
        $disabledAt = $this->now->modify('-3 days');
        $profile = $this->seedProfile('predisabled');
        $profile->user->disabledAt = $disabledAt;
        $this->em()->flush();

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(churnedSurveys: 1), $result);
        self::assertEquals($disabledAt, $profile->user->disabledAt);
        self::assertEquals($this->now, $this->trial($profile)->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
    }

    public function test_a_second_sweep_is_a_complete_no_op(): void
    {
        $this->seedProfile('churntwo');

        $handler = $this->handler();
        ($handler)(new RunTrialSweepCommand($this->now));
        $second = ($handler)(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(), $second);
        self::assertCount(1, $this->mailer->sent);
    }

    /** A comp keeps the account alive, so the trial's ending disables nobody. */
    public function test_an_expired_trial_beside_a_comp_is_marked_but_disables_nothing(): void
    {
        $profile = $this->seedProfile('compedtrial');
        $comp = $this->grant(BillingGrants::comp($profile));

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(churnedSurveys: 1), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertEquals($this->now, $this->trial($profile)->surveySentAt);
        self::assertNull($comp->endsAt);
        self::assertNull($comp->surveySentAt);
    }

    /** @return iterable<string, array{BillingStatus}> */
    public static function subscriberStatuses(): iterable
    {
        yield 'active' => [BillingStatus::Active];

        yield 'past due' => [BillingStatus::PastDue];
    }

    #[DataProvider('subscriberStatuses')]
    public function test_subscriber_past_trial_end_is_surveyed_once_and_never_disabled(BillingStatus $status): void
    {
        $profile = $this->seedProfile('subscriber');
        $this->grant(BillingGrants::stripe($profile, $status, $this->now->modify('+30 days')));

        $handler = $this->handler();
        $result = ($handler)(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(subscriberSurveys: 1), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertEquals($this->now, $this->trial($profile)->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/trial_end_survey_subscribed.html.twig', $email->getHtmlTemplate());

        self::assertEquals(new TrialSweepResult(), ($handler)(new RunTrialSweepCommand($this->now)));
        self::assertCount(1, $this->mailer->sent);
    }

    public function test_canceled_with_a_future_paid_period_is_untouched(): void
    {
        $profile = $this->seedProfile('cancelfuture');
        $canceled = $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('+3 days')));

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        // The trial pass still marks the expired trial, but sends nothing: the
        // cancellation pass owns this account's ending.
        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($canceled->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    public function test_canceled_past_its_paid_period_is_disabled_and_gets_the_cancel_survey(): void
    {
        $profile = $this->seedProfile('cancelpast');
        $canceled = $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('-1 hour')));

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(disabled: 1, cancelSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $canceled->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/cancel_survey.html.twig', $email->getHtmlTemplate());
    }

    /**
     * A canceled Stripe grant beside a comp leaves the account alone: the comp
     * still runs, so nothing has ended for this user.
     */
    public function test_a_canceled_subscription_beside_a_comp_disables_nothing(): void
    {
        $profile = $this->seedProfile('compedcancel');
        $canceled = $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('-1 hour')));
        $comp = $this->grant(BillingGrants::comp($profile));

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($canceled->surveySentAt);
        self::assertNull($comp->endsAt);
        self::assertCount(0, $this->mailer->sent);
    }

    /**
     * A canceled Stripe grant during a running trial waits for the trial. It
     * covers both an abandoned 3D Secure prompt and a real cancellation: the
     * trial is a grant like any other, and one rule decides access.
     */
    public function test_a_canceled_subscription_inside_a_running_trial_is_left_alone(): void
    {
        $profile = $this->seedProfile('canceledintrial', $this->now->modify('+5 days'));
        $canceled = $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('-1 hour')));

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($canceled->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    public function test_canceled_user_already_disabled_by_the_webhook_still_gets_the_cancel_survey(): void
    {
        $disabledAt = $this->now->modify('-2 hours');
        $profile = $this->seedProfile('canceldisabled');
        $canceled = $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('-1 hour')));
        $profile->user->disabledAt = $disabledAt;
        $this->em()->flush();

        $result = ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(cancelSurveys: 1), $result);
        self::assertEquals($disabledAt, $profile->user->disabledAt);
        self::assertEquals($this->now, $canceled->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
    }

    public function test_billing_disabled_returns_a_zero_result_and_touches_nothing(): void
    {
        $profile = $this->seedProfile('billingoff');

        $result = ($this->handler(['billing.enabled' => false] + self::FLAGS))(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($this->trial($profile)->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    public function test_counts_reflect_processed_rows_not_delivered_emails(): void
    {
        // No survey URL configured: the send is skipped, but the row still
        // counts as processed and its marker still commits — surveys are
        // time-sensitive, so a URL configured later must not spray stale
        // surveys at long-past trial-enders.
        $profile = $this->seedProfile('nourl');

        $result = ($this->handler(['billing.enabled' => true]))(new RunTrialSweepCommand($this->now));

        self::assertEquals(new TrialSweepResult(disabled: 1, churnedSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $this->trial($profile)->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    public function test_a_failing_send_counts_the_row_as_failed_and_the_batch_continues(): void
    {
        $first = $this->seedProfile('failedsendone');
        $second = $this->seedProfile('failedsendtwo');

        // Throws on the first send only: one row fails after its markers
        // commit, the next row must still be processed.
        $mailer = new class implements MailerInterface {
            private int $calls = 0;

            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if (1 === ++$this->calls) {
                    throw new \RuntimeException('SMTP down');
                }
            }
        };

        $container = static::getContainer();
        $featureFlags = FeatureFlags::service(self::FLAGS);
        $translator = $container->get(TranslatorInterface::class);
        $handler = new RunTrialSweepHandler(
            $container->get(SubscriptionRepository::class),
            new TrialEndSurveyEmailSender($mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            new CancelSurveyEmailSender($mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            $featureFlags,
            $container->get(EntityManagerInterface::class),
            new NullLogger(),
            $this->audit->auditor,
        );

        $result = ($handler)(new RunTrialSweepCommand($this->now));

        // The failing row's counts are lost (the throw lands after its markers
        // commit, before its tallies), the surviving row's are kept.
        self::assertEquals(new TrialSweepResult(disabled: 1, churnedSurveys: 1, failed: 1), $result);
        self::assertEquals($this->now, $this->trial($first)->surveySentAt);
        self::assertEquals($this->now, $this->trial($second)->surveySentAt);
        self::assertEquals($this->now, $first->user->disabledAt);
        self::assertEquals($this->now, $second->user->disabledAt);
    }

    /** @param array<string, bool|int|string> $flags */
    private function handler(array $flags = self::FLAGS): RunTrialSweepHandler
    {
        $container = static::getContainer();
        $featureFlags = FeatureFlags::service($flags);
        $translator = $container->get(TranslatorInterface::class);
        $this->mailer = new RecordingMailer();

        return new RunTrialSweepHandler(
            $container->get(SubscriptionRepository::class),
            new TrialEndSurveyEmailSender($this->mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            new CancelSurveyEmailSender($this->mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            $featureFlags,
            $container->get(EntityManagerInterface::class),
            $this->logger,
            $this->audit->auditor,
        );
    }

    private function seedProfile(string $username, ?\DateTimeImmutable $trialEndsAt = null): BillingProfile
    {
        $scenario = new BillingScenario(static::getContainer());

        return $scenario->profile($scenario->verifiedUser($username), $trialEndsAt ?? $this->now->modify('-1 day'));
    }

    private function grant(Subscription $subscription): Subscription
    {
        return new BillingScenario(static::getContainer())->grant($subscription);
    }

    private function trial(BillingProfile $profile): Subscription
    {
        return $profile->latestSubscriptionOfKind(SubscriptionKind::Trial)
            ?? throw new \LogicException('every seeded profile has a trial');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function test_an_expired_trial_records_the_disable_with_its_reason(): void
    {
        $profile = $this->seedProfile('auditchurn');

        ($this->handler())(new RunTrialSweepCommand($this->now));

        $record = $this->audit->record('billing.trial_sweep.disabled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $profile->user->id, $record->subject->id);
        self::assertSame([
            'userId' => (string) $profile->user->id,
            'reason' => 'trial_expired',
        ], $record->context);

        self::assertSame(['billing.trial_sweep.disabled'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_settled_cancellation_records_the_disable_with_its_own_reason(): void
    {
        $profile = $this->seedProfile('auditcancel');
        $this->grant(BillingGrants::stripe($profile, BillingStatus::Canceled, $this->now->modify('-1 hour')));

        ($this->handler())(new RunTrialSweepCommand($this->now));

        $record = $this->audit->record('billing.trial_sweep.disabled');
        self::assertNotNull($record->subject);
        self::assertSame((string) $profile->user->id, $record->subject->id);
        self::assertSame([
            'userId' => (string) $profile->user->id,
            'reason' => 'subscription_canceled',
        ], $record->context);
    }

    /** One operation, two passes: only `reason` tells the records apart. */
    public function test_both_passes_in_one_sweep_record_one_disable_each(): void
    {
        $churned = $this->seedProfile('auditboth');
        $canceled = $this->seedProfile('auditbothcancel');
        $this->grant(BillingGrants::stripe($canceled, BillingStatus::Canceled, $this->now->modify('-1 hour')));

        ($this->handler())(new RunTrialSweepCommand($this->now));

        $records = $this->audit->records('billing.trial_sweep.disabled');
        self::assertCount(2, $records);
        self::assertSame([
            ['userId' => (string) $churned->user->id, 'reason' => 'trial_expired'],
            ['userId' => (string) $canceled->user->id, 'reason' => 'subscription_canceled'],
        ], array_map(static fn (AuditEvent $event): array => $event->context, $records));
    }

    /** A sweep that disables nobody must record nothing. */
    public function test_a_sweep_that_disables_nobody_records_nothing(): void
    {
        $profile = $this->seedProfile('auditcomped');
        $this->grant(BillingGrants::comp($profile));

        ($this->handler())(new RunTrialSweepCommand($this->now));

        self::assertSame([], $this->audit->operations());
    }

    /**
     * The handler keeps its logger for the sweep diagnostics, so the
     * reflection check cannot apply. The disable operation must reach the log
     * stream through the sink alone, and the survey lines must stay direct.
     */
    public function test_the_survey_diagnostics_still_log_directly_and_the_disable_does_not(): void
    {
        $this->seedProfile('auditsplit');

        ($this->handler())(new RunTrialSweepCommand($this->now));

        $messages = array_map(static fn (array $entry): string => $entry['message'], $this->logger->records);
        self::assertContains('billing.trial_sweep.survey_sent', $messages);
        DirectLogging::assertOperationNotLoggedBy($this->logger, 'billing.trial_sweep.disabled');
    }

    /**
     * The whole log line, not only its message. The sweep runs with no
     * security token, so the record carries the channel the process declared
     * and no actor at all.
     */
    public function test_the_log_line_the_sink_emits_carries_the_cron_channel_and_no_actor(): void
    {
        $auditContext = self::getContainer()->get(AuditContext::class);
        self::assertInstanceOf(AuditContext::class, $auditContext);
        $auditContext->channel = AuditChannel::Cron;

        $profile = $this->seedProfile('auditcron');

        ($this->handler())(new RunTrialSweepCommand($this->now));

        $record = $this->audit->record('billing.trial_sweep.disabled');
        self::assertNull($record->actor);
        self::assertNull($record->credential);
        self::assertSame(AuditChannel::Cron->value, $record->channel);

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'userId' => (string) $profile->user->id,
            'reason' => 'trial_expired',
            'outcome' => 'success',
            'channel' => AuditChannel::Cron->value,
            'subjectType' => 'user',
            'subjectId' => (string) $profile->user->id,
        ], $this->audit->domainChannel->records[0]['context']);
    }
}
