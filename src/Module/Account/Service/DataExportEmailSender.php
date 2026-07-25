<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DataExportEmailSender
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

    public function send(DataExport $export, string $rawToken): void
    {
        $downloadUrl = $this->urlGenerator->generate(
            'app_account_export_download',
            ['id' => (string) $export->id, 'token' => $rawToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($export->user->email)
            ->subject($this->translator->trans('account.email.data_export.subject'))
            ->htmlTemplate('@Account/email/data_export.html.twig')
            ->context(['downloadUrl' => $downloadUrl]);

        $this->mailer->send($email);
    }
}
