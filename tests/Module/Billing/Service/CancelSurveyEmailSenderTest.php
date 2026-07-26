<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\CancelSurveyEmailSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class CancelSurveyEmailSenderTest extends TestCase
{
    public function test_send_skips_and_logs_when_the_url_flag_is_unset(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'hashed');

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
                    static fn (array $context): bool => CancelSurveyEmailSender::URL_FLAG === $context['flag']
                        && array_key_exists('userId', $context),
                ),
            );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $sender = new CancelSurveyEmailSender(
            mailer: $mailer,
            translator: $translator,
            featureFlags: $flags,
            logger: $logger,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );

        self::assertFalse($sender->send($user));
    }

    public function test_send_dispatches_the_survey_email_when_the_url_flag_is_set(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'hashed');

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturnCallback(
            static fn (string $name, string $default = ''): string => CancelSurveyEmailSender::URL_FLAG === $name
                ? 'https://survey.example.com/cancel'
                : $default,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $sender = new CancelSurveyEmailSender(
            mailer: $mailer,
            translator: $translator,
            featureFlags: $flags,
            logger: new NullLogger(),
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );

        self::assertTrue($sender->send($user));

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame('alice@example.com', $sentEmail->getTo()[0]->getAddress());
        self::assertSame('billing.email.cancel_survey.subject', $sentEmail->getSubject());
        self::assertSame('@Billing/email/cancel_survey.html.twig', $sentEmail->getHtmlTemplate());
        self::assertSame('https://survey.example.com/cancel', $sentEmail->getContext()['surveyUrl']);
    }
}
