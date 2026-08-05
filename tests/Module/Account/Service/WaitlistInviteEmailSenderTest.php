<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WaitlistInviteEmailSenderTest extends TestCase
{
    private ?TemplatedEmail $sentEmail = null;

    private function makeSender(?User $existingUser): WaitlistInviteEmailSender
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturnCallback(function (RawMessage $message): void {
            self::assertInstanceOf(TemplatedEmail::class, $message);
            $this->sentEmail = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            function (string $route, array $parameters, int $referenceType): string {
                self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType);

                return sprintf('https://example.com/%s?%s', $route, http_build_query($parameters));
            },
        );

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn($existingUser);

        return new WaitlistInviteEmailSender(
            mailer: $mailer,
            translator: $translator,
            urlGenerator: $urlGenerator,
            users: $users,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Example',
        );
    }

    public function test_an_entry_with_an_existing_account_gets_the_returning_variant(): void
    {
        $existingUser = new User(fullName: 'Returning User', email: 'returning@example.com', password: 'x');
        $sender = $this->makeSender($existingUser);

        $sender->send(new WaitlistEntry('returning@example.com'), 'plain-token');

        self::assertNotNull($this->sentEmail);
        self::assertSame('returning@example.com', $this->sentEmail->getTo()[0]->getAddress());
        self::assertSame('account.email.waitlist_invite_returning.subject', $this->sentEmail->getSubject());
        self::assertSame('@Account/email/waitlist_invite_returning.html.twig', $this->sentEmail->getHtmlTemplate());
        self::assertSame('https://example.com/app_billing_subscribe?invite=plain-token', $this->sentEmail->getContext()['inviteUrl'] ?? null);
    }

    public function test_an_entry_without_an_account_gets_the_registration_variant(): void
    {
        $sender = $this->makeSender(null);

        $sender->send(new WaitlistEntry('fresh@example.com'), 'plain-token');

        self::assertNotNull($this->sentEmail);
        self::assertSame('fresh@example.com', $this->sentEmail->getTo()[0]->getAddress());
        self::assertSame('account.email.waitlist_invite.subject', $this->sentEmail->getSubject());
        self::assertSame('@Account/email/waitlist_invite.html.twig', $this->sentEmail->getHtmlTemplate());
        self::assertSame('https://example.com/app_register?invite=plain-token', $this->sentEmail->getContext()['inviteUrl'] ?? null);
    }
}
