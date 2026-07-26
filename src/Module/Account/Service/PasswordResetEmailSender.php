<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PasswordResetEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    public function send(User $user): void
    {
        $token = $user->generatePasswordResetToken();

        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->email)
            ->subject($this->translator->trans('account.email.reset_password.subject'))
            ->htmlTemplate('@Account/email/reset_password.html.twig')
            ->context(['resetUrl' => $resetUrl]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // A persisted token the user never received would make
            // hasActivePasswordResetToken() silently block every re-request
            // until the token expires — never flush on a failed enqueue.
            $user->clearPasswordResetToken();

            throw $e;
        }

        $this->em->flush();
    }
}
