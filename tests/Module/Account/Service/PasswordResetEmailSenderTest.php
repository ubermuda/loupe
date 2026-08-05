<?php

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Service\PasswordResetEmailSender;
use App\Routing\PinnedUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PasswordResetEmailSenderTest extends TestCase
{
    private function makeUser(): User
    {
        return new User(fullName: 'Test User', email: 'test@example.com', password: 'hashed');
    }

    private function makeSender(MailerInterface&Stub $mailer, EntityManagerInterface&MockObject $em): PasswordResetEmailSender
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn(new RequestContext());
        $router->method('generate')->willReturn('https://example.com/reset-password/token');
        $urlGenerator = new PinnedUrlGenerator($router, 'https://example.com');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new PasswordResetEmailSender(
            mailer: $mailer,
            em: $em,
            translator: $translator,
            urlGenerator: $urlGenerator,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Example',
        );
    }

    public function test_successful_send_persists_token(): void
    {
        $mailer = $this->createStub(MailerInterface::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $user = $this->makeUser();
        $this->makeSender($mailer, $em)->send($user);

        $this->assertTrue($user->hasActivePasswordResetToken());
    }

    public function test_failed_send_does_not_persist_token(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('SMTP down'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $user = $this->makeUser();
        $sender = $this->makeSender($mailer, $em);

        try {
            $sender->send($user);
            $this->fail('Expected TransportException to be rethrown.');
        } catch (TransportException) {
        }

        // The in-memory token must be cleared too, or a later flush in the same
        // request would persist it and block re-requests until expiry.
        $this->assertFalse($user->hasActivePasswordResetToken());
    }
}
