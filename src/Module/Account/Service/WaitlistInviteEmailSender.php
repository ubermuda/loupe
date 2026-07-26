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
        // A returning account (it exists — only disabled accounts can hold an
        // invitable row) cannot re-register: its invite leads to the subscribe
        // page, where the token doubles as the registration-cap bypass at
        // checkout. A fresh address gets the registration link as before. The
        // variant is decided here at send time, not persisted at join time,
        // because account state can change in between — e.g. the account gets
        // deleted, so the address must fall back to the registration variant;
        // a join-time flag would have gone stale.
        $returning = null !== $this->users->findOneByEmail($entry->email);

        $inviteUrl = $this->urlGenerator->generate(
            $returning ? 'app_billing_subscribe' : 'app_register',
            ['invite' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $subjectKey = $returning
            ? 'account.email.waitlist_invite_returning.subject'
            : 'account.email.waitlist_invite.subject';
        $template = $returning
            ? '@Account/email/waitlist_invite_returning.html.twig'
            : '@Account/email/waitlist_invite.html.twig';

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($entry->email)
            ->subject($this->translator->trans($subjectKey))
            ->htmlTemplate($template)
            ->context(['inviteUrl' => $inviteUrl]);

        $this->mailer->send($email);
    }
}
