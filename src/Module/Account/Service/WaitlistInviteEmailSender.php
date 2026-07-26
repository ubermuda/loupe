<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
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
        private readonly UserRepository $users,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    public function send(WaitlistEntry $entry, string $plainToken): void
    {
        // A returning account (it exists — only disabled accounts can hold a
        // waitlist row, enabled ones are skipped at join) cannot re-register:
        // its invite leads to the subscribe page, where the token doubles as
        // the registration-cap bypass at checkout. A fresh address gets the
        // registration link as before.
        $returning = null !== $this->users->findOneByEmail($entry->email);

        $route = $returning ? 'app_billing_subscribe' : 'app_register';
        $inviteUrl = $this->urlGenerator->generate(
            $route,
            ['invite' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $variant = $returning ? 'waitlist_invite_returning' : 'waitlist_invite';

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($entry->email)
            ->subject($this->translator->trans(sprintf('account.email.%s.subject', $variant)))
            ->htmlTemplate(sprintf('@Account/email/%s.html.twig', $variant))
            ->context(['inviteUrl' => $inviteUrl]);

        $this->mailer->send($email);
    }
}
