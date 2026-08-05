<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\TrialEndSurveyEmailSender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class TrialEndSurveyEmailSenderTest extends TestCase
{
    /** @return iterable<string, array{bool, string, string, string}> */
    public static function variants(): iterable
    {
        yield 'churned' => [
            false,
            TrialEndSurveyEmailSender::URL_FLAG_CHURNED,
            'billing.email.trial_end_survey.churned.subject',
            '@Billing/email/trial_end_survey_churned.html.twig',
        ];

        yield 'subscribed' => [
            true,
            TrialEndSurveyEmailSender::URL_FLAG_SUBSCRIBED,
            'billing.email.trial_end_survey.subscribed.subject',
            '@Billing/email/trial_end_survey_subscribed.html.twig',
        ];
    }

    /** @return iterable<string, array{bool, string}> */
    public static function flagsByVariant(): iterable
    {
        yield 'churned' => [false, TrialEndSurveyEmailSender::URL_FLAG_CHURNED];

        yield 'subscribed' => [true, TrialEndSurveyEmailSender::URL_FLAG_SUBSCRIBED];
    }

    #[DataProvider('flagsByVariant')]
    public function test_send_skips_and_logs_when_the_url_flag_is_unset(bool $subscribed, string $flag): void
    {
        $user = new User('Alice A', 'alice@example.com', 'hashed');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn('');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'billing.survey.skipped_no_url',
                self::callback(
                    static fn (array $context): bool => $flag === $context['flag'] && array_key_exists('userId', $context),
                ),
            );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $sender = new TrialEndSurveyEmailSender(
            mailer: $mailer,
            translator: $translator,
            featureFlags: $flags,
            logger: $logger,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );

        self::assertFalse($sender->send($user, $subscribed));
    }

    #[DataProvider('variants')]
    public function test_send_dispatches_the_variant_email_when_the_url_flag_is_set(
        bool $subscribed,
        string $flag,
        string $subjectKey,
        string $template,
    ): void {
        $user = new User('Alice A', 'alice@example.com', 'hashed');

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturnCallback(
            static fn (string $name, string $default = ''): string => $name === $flag
                ? 'https://survey.example.com/form'
                : $default,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $sender = new TrialEndSurveyEmailSender(
            mailer: $mailer,
            translator: $translator,
            featureFlags: $flags,
            logger: new NullLogger(),
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );

        self::assertTrue($sender->send($user, $subscribed));

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame('alice@example.com', $sentEmail->getTo()[0]->getAddress());
        self::assertSame($subjectKey, $sentEmail->getSubject());
        self::assertSame($template, $sentEmail->getHtmlTemplate());
        self::assertSame('https://survey.example.com/form', $sentEmail->getContext()['surveyUrl']);
    }
}
