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
 * The end-of-trial survey. Two variants of one email type: "churned" for a
 * trial that ended without a subscription, "subscribed" for one that
 * converted. The survey itself is an external form; the flag holds its URL,
 * and an empty flag skips the send — the caller still marks the profile,
 * because surveys are time-sensitive: a URL configured later must not spray
 * stale surveys at long-past trial-enders.
 */
class TrialEndSurveyEmailSender
{
    public const string URL_FLAG_CHURNED = 'billing.survey_url.churned';
    public const string URL_FLAG_SUBSCRIBED = 'billing.survey_url.subscribed';

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
    public function send(User $user, bool $subscribed): bool
    {
        $flag = $subscribed ? self::URL_FLAG_SUBSCRIBED : self::URL_FLAG_CHURNED;
        $surveyUrl = $this->featureFlags->getStringValue($flag);

        if ('' === $surveyUrl) {
            $this->logger->info('billing.survey_skipped_no_url', [
                'userId' => (string) $user->id,
                'flag' => $flag,
            ]);

            return false;
        }

        $variant = $subscribed ? 'subscribed' : 'churned';

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->email)
            ->subject($this->translator->trans(sprintf('billing.email.trial_end_survey.%s.subject', $variant)))
            ->htmlTemplate(sprintf('@Billing/email/trial_end_survey_%s.html.twig', $variant))
            ->context(['surveyUrl' => $surveyUrl]);

        $this->mailer->send($email);

        return true;
    }
}
