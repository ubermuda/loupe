<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\CancelSurveyEmailSender;
use App\Module\Billing\Service\TrialEndSurveyEmailSender;
use App\Module\Billing\Service\TrialEndSweeper;
use App\Module\Billing\Service\TrialSweepResult;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\FeatureFlags;
use App\Tests\Support\RecordingMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The row locking (PESSIMISTIC_WRITE + refresh + re-check) needs the real
 * database, hence KernelTestCase. The concurrent race itself cannot be
 * exercised here — dama wraps each test in a single connection's transaction,
 * so two overlapping database transactions are not expressible. The marker
 * idempotence tests (a second sweep changes nothing) are the sequential
 * regression guard for that per-row shape.
 */
final class TrialEndSweeperTest extends KernelTestCase
{
    private const array FLAGS = [
        'billing.enabled' => true,
        TrialEndSurveyEmailSender::URL_FLAG_CHURNED => 'https://survey.example.com/churned',
        TrialEndSurveyEmailSender::URL_FLAG_SUBSCRIBED => 'https://survey.example.com/subscribed',
        CancelSurveyEmailSender::URL_FLAG => 'https://survey.example.com/canceled',
    ];

    private RecordingMailer $mailer;

    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->now = new \DateTimeImmutable('2026-07-25 12:00:00');
    }

    public function test_expired_trial_disables_the_user_and_sends_the_churned_survey(): void
    {
        $profile = $this->seedProfile('churnone', BillingStatus::Trialing);

        $result = $this->sweeper()->sweep($this->now);

        self::assertEquals(new TrialSweepResult(disabled: 1, churnedSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $profile->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/trial_end_survey_churned.html.twig', $email->getHtmlTemplate());
        self::assertSame('churnone@example.com', $email->getTo()[0]->getAddress());
    }

    public function test_a_second_sweep_is_a_complete_no_op(): void
    {
        $this->seedProfile('churntwo', BillingStatus::Trialing);

        $sweeper = $this->sweeper();
        $sweeper->sweep($this->now);
        $second = $sweeper->sweep($this->now);

        self::assertEquals(new TrialSweepResult(), $second);
        self::assertCount(1, $this->mailer->sent);
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
        $profile = $this->seedProfile('subscriber', $status);

        $sweeper = $this->sweeper();
        $result = $sweeper->sweep($this->now);

        self::assertEquals(new TrialSweepResult(subscriberSurveys: 1), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertEquals($this->now, $profile->surveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/trial_end_survey_subscribed.html.twig', $email->getHtmlTemplate());

        self::assertEquals(new TrialSweepResult(), $sweeper->sweep($this->now));
        self::assertCount(1, $this->mailer->sent);
    }

    public function test_canceled_with_a_future_paid_period_is_untouched(): void
    {
        $profile = $this->seedProfile('cancelfuture', BillingStatus::Canceled, currentPeriodEnd: $this->now->modify('+3 days'));

        $result = $this->sweeper()->sweep($this->now);

        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($profile->cancelSurveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    /** @return iterable<string, array{?string}> */
    public static function endedPeriods(): iterable
    {
        yield 'period end in the past' => ['-1 hour'];

        yield 'no period end recorded' => [null];
    }

    #[DataProvider('endedPeriods')]
    public function test_canceled_past_its_paid_period_is_disabled_and_gets_the_cancel_survey(?string $periodEndModifier): void
    {
        $profile = $this->seedProfile(
            'cancelpast',
            BillingStatus::Canceled,
            currentPeriodEnd: null === $periodEndModifier ? null : $this->now->modify($periodEndModifier),
        );

        $result = $this->sweeper()->sweep($this->now);

        self::assertEquals(new TrialSweepResult(disabled: 1, cancelSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $profile->cancelSurveySentAt);
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame('@Billing/email/cancel_survey.html.twig', $email->getHtmlTemplate());
    }

    public function test_canceled_user_already_disabled_by_the_webhook_still_gets_the_cancel_survey(): void
    {
        $disabledAt = $this->now->modify('-2 hours');
        $profile = $this->seedProfile('canceldisabled', BillingStatus::Canceled, currentPeriodEnd: $this->now->modify('-1 hour'));
        $profile->user->disabledAt = $disabledAt;
        $this->em()->flush();

        $result = $this->sweeper()->sweep($this->now);

        self::assertEquals(new TrialSweepResult(cancelSurveys: 1), $result);
        self::assertEquals($disabledAt, $profile->user->disabledAt);
        self::assertEquals($this->now, $profile->cancelSurveySentAt);
        self::assertCount(1, $this->mailer->sent);
    }

    public function test_billing_disabled_returns_a_zero_result_and_touches_nothing(): void
    {
        $profile = $this->seedProfile('billingoff', BillingStatus::Trialing);

        $result = $this->sweeper(['billing.enabled' => false] + self::FLAGS)->sweep($this->now);

        self::assertEquals(new TrialSweepResult(), $result);
        self::assertNull($profile->user->disabledAt);
        self::assertNull($profile->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    public function test_counts_reflect_processed_rows_not_delivered_emails(): void
    {
        // No survey URL configured: the send is skipped, but the row still
        // counts as processed and its marker still commits — surveys are
        // time-sensitive, so a URL configured later must not spray stale
        // surveys at long-past trial-enders.
        $profile = $this->seedProfile('nourl', BillingStatus::Trialing);

        $result = $this->sweeper(['billing.enabled' => true])->sweep($this->now);

        self::assertEquals(new TrialSweepResult(disabled: 1, churnedSurveys: 1), $result);
        self::assertEquals($this->now, $profile->user->disabledAt);
        self::assertEquals($this->now, $profile->surveySentAt);
        self::assertCount(0, $this->mailer->sent);
    }

    /** @param array<string, bool|int|string> $flags */
    private function sweeper(array $flags = self::FLAGS): TrialEndSweeper
    {
        $container = static::getContainer();
        $featureFlags = FeatureFlags::service($flags);
        $translator = $container->get(TranslatorInterface::class);
        $this->mailer = new RecordingMailer();

        return new TrialEndSweeper(
            $container->get(BillingProfileRepository::class),
            new TrialEndSurveyEmailSender($this->mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            new CancelSurveyEmailSender($this->mailer, $translator, $featureFlags, new NullLogger(), 'noreply@example.com', 'Loupe'),
            $featureFlags,
            $container->get(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private function seedProfile(
        string $username,
        BillingStatus $status,
        ?\DateTimeImmutable $trialEndsAt = null,
        ?\DateTimeImmutable $currentPeriodEnd = null,
    ): BillingProfile {
        $scenario = new BillingScenario(static::getContainer());
        $user = $scenario->verifiedUser($username);
        $profile = $scenario->profile($user, $trialEndsAt ?? $this->now->modify('-1 day'));
        $profile->status = $status;
        $profile->currentPeriodEnd = $currentPeriodEnd;
        $this->em()->flush();

        return $profile;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
