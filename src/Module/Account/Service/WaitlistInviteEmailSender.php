<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class WaitlistInviteEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    public function send(WaitlistEntry $entry, string $plainToken): void
    {
        $inviteUrl = $this->urlGenerator->generate(
            'app_register',
            ['invite' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($entry->email)
            ->subject($this->translator->trans('account.email.waitlist_invite.subject'))
            ->htmlTemplate('@Account/email/waitlist_invite.html.twig')
            ->context(['inviteUrl' => $inviteUrl]);

        $this->mailer->send($email);
    }
}
