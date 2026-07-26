<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The post-cancellation survey. Sent only once the canceled subscription's
 * paid period is over — not when the user clicks cancel. The survey itself
 * is an external form; the flag holds its URL, and an empty flag skips the
 * send — the caller still marks the profile, because surveys are
 * time-sensitive: a URL configured later must not spray stale surveys at
 * users who canceled long ago.
 */
class CancelSurveyEmailSender
{
    public const string URL_FLAG = 'billing.survey_url.canceled';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly FeatureFlagService $featureFlags,
        private readonly LoggerInterface $logger,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    /** @return bool true when an email was handed to the mailer */
    public function send(User $user): bool
    {
        $surveyUrl = $this->featureFlags->getStringValue(self::URL_FLAG);

        if ('' === $surveyUrl) {
            $this->logger->info('billing.survey.skipped_no_url', [
                'userId' => (string) $user->id,
                'flag' => self::URL_FLAG,
            ]);

            return false;
        }

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->email)
            ->subject($this->translator->trans('billing.email.cancel_survey.subject'))
            ->htmlTemplate('@Billing/email/cancel_survey.html.twig')
            ->context(['surveyUrl' => $surveyUrl]);

        $this->mailer->send($email);

        return true;
    }
}
