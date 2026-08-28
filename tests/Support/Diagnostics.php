<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Diagnostics\AgentAccountCheck;
use App\Module\Billing\Diagnostics\StripeCheck;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\SendmailTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\HealthCheckBundle\Check\FailedMessagesCheck;
use Ubermuda\HealthCheckBundle\Check\MailerSenderCheck;
use Ubermuda\HealthCheckBundle\Check\MailerTransportCheck;
use Ubermuda\HealthCheckBundle\Check\MercureCheck;
use Ubermuda\HealthCheckBundle\Check\WorkerCheck;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsHandler;

/**
 * Builds a real RunDiagnosticsHandler wired to values a test controls, with the
 * checks in the order the container gives them so a test sees the real report.
 *
 * The bundle ships an equivalent helper, but only under its `autoload-dev`, so
 * a consumer cannot reach it. The defaults are the combination that touches no
 * network at all — a null mail transport and no Mercure hub — so a test that
 * does not care about the checks still cannot hang on a socket.
 */
final class Diagnostics
{
    public static function handler(
        Connection $connection,
        ?string $mailerDsn = 'null://null',
        ?string $mailerFromAddress = 'noreply@localhost',
        ?string $mercureUrl = null,
        ?string $mercureJwtSecret = null,
        ?string $stripeSecretKey = null,
        ?string $stripeWebhookSecret = null,
        ?HttpClientInterface $httpClient = null,
        ?FeatureFlagService $featureFlags = null,
    ): RunDiagnosticsHandler {
        $logger = new NullLogger();

        return new RunDiagnosticsHandler([
            new MailerTransportCheck(
                new Transport([
                    new NullTransportFactory(),
                    new SendmailTransportFactory(),
                    new EsmtpTransportFactory(),
                ]),
                $mailerDsn,
                $logger,
            ),
            new MailerSenderCheck($mailerFromAddress),
            new WorkerCheck($connection, $logger),
            new FailedMessagesCheck($connection, $logger),
            new MercureCheck($httpClient ?? new MockHttpClient(), $mercureUrl, $mercureJwtSecret, $logger),
            new AgentAccountCheck($connection, $logger),
            new StripeCheck($featureFlags ?? FeatureFlags::service(), $stripeSecretKey, $stripeWebhookSecret),
        ]);
    }
}
