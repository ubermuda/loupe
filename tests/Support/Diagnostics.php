<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Diagnostics\Command\RunDiagnosticsHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\SendmailTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Builds a real RunDiagnosticsHandler wired to values a test controls.
 *
 * The handler is `final readonly`, so it cannot be mocked; controller tests
 * substitute a genuine instance instead. The defaults are the combination that
 * touches no network at all — a null mail transport and no Mercure hub — so a
 * test that does not care about the checks still cannot hang on a socket.
 */
final class Diagnostics
{
    public static function handler(
        Connection $connection,
        string $mailerDsn = 'null://null',
        string $mailerFromAddress = 'noreply@localhost',
        ?string $mercureUrl = null,
        ?string $mercureJwtSecret = null,
        ?string $stripeSecretKey = null,
        ?string $stripeWebhookSecret = null,
        ?HttpClientInterface $httpClient = null,
        ?FeatureFlagService $featureFlags = null,
    ): RunDiagnosticsHandler {
        return new RunDiagnosticsHandler(
            connection: $connection,
            httpClient: $httpClient ?? new MockHttpClient(),
            featureFlags: $featureFlags ?? FeatureFlags::service(),
            logger: new NullLogger(),
            transportFactory: new Transport([
                new NullTransportFactory(),
                new SendmailTransportFactory(),
                new EsmtpTransportFactory(),
            ]),
            mailerDsn: $mailerDsn,
            mailerFromAddress: $mailerFromAddress,
            mercureUrl: $mercureUrl,
            mercureJwtSecret: $mercureJwtSecret,
            stripeSecretKey: $stripeSecretKey,
            stripeWebhookSecret: $stripeWebhookSecret,
        );
    }
}
