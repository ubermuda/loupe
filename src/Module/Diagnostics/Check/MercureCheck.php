<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

use App\Module\Diagnostics\Diagnostic;
use App\Module\Diagnostics\DiagnosticInterface;
use App\Module\Diagnostics\DiagnosticState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Site review saves submissions whether or not a hub is running, so an absent
 * hub degrades the product rather than breaking it — a warning, not a failure.
 * Any HTTP answer at all proves a hub is listening; the endpoint legitimately
 * rejects an unauthenticated, topic-less GET, so only a transport-level error
 * means "not there".
 */
final readonly class MercureCheck implements DiagnosticInterface
{
    /**
     * A firewalled hub host must make the page slow, never make it hang. timeout
     * alone does not deliver that: it bounds idle time, so a hub dripping bytes
     * is never idle and never cut off. max_duration bounds the whole exchange.
     */
    private const float PROBE_TIMEOUT_SECONDS = 3.0;
    private const float PROBE_MAX_DURATION_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,

        #[Autowire('%env(default::MERCURE_URL)%')]
        private ?string $mercureUrl,

        // MERCURE_JWT_SECRET ships without a default on purpose, so it must be
        // read through `default::` — resolving it as a plain env var would make
        // this page fatal on exactly the instance that has not set it yet.
        #[Autowire('%env(default::MERCURE_JWT_SECRET)%')]
        private ?string $mercureJwtSecret,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 30;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        if (null === $this->mercureUrl || '' === $this->mercureUrl
            || null === $this->mercureJwtSecret || '' === $this->mercureJwtSecret) {
            return new Diagnostic('mercure', DiagnosticState::Warning, 'account.system_status.mercure.unconfigured');
        }

        try {
            $statusCode = $this->httpClient
                ->request('GET', $this->mercureUrl, [
                    'timeout' => self::PROBE_TIMEOUT_SECONDS,
                    'max_duration' => self::PROBE_MAX_DURATION_SECONDS,
                ])
                ->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('account.system_status.mercure_unreachable', ['exception' => $e]);

            return new Diagnostic('mercure', DiagnosticState::Warning, 'account.system_status.mercure.unreachable');
        }

        return new Diagnostic(
            'mercure',
            DiagnosticState::Ok,
            'account.system_status.mercure.reachable',
            ['%status%' => (string) $statusCode],
        );
    }
}
