<?php

declare(strict_types=1);

namespace App\Module\Mcp\Controller;

use App\Controller\AppController;
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP HTTP endpoint.
 *
 * Replaces symfony/mcp-bundle's McpController (which is final and hardcodes the
 * DNS-rebinding-protection host allowlist to localhost variants) so the endpoint
 * is reachable on the app's real hostname. The allowlist is configurable via the
 * MCP_ALLOWED_HOSTS env var; the CORS and protocol-version protections are kept.
 */
#[Route(
    '/mcp',
    name: 'app_mcp_endpoint',
    methods: ['GET', 'POST', 'DELETE', 'OPTIONS'],
)]
final class McpEndpointController extends AppController
{
    /**
     * @param list<string> $allowedHosts hostnames (without port) permitted past DNS-rebinding protection
     */
    public function __construct(
        #[Autowire(service: 'mcp.server')]
        private readonly Server $server,

        #[Autowire(service: 'mcp.psr_http_factory')]
        private readonly HttpMessageFactoryInterface $httpMessageFactory,

        #[Autowire(service: 'mcp.http_foundation_factory')]
        private readonly HttpFoundationFactoryInterface $httpFoundationFactory,

        #[Autowire(service: 'mcp.psr17_factory')]
        private readonly ResponseFactoryInterface $responseFactory,

        #[Autowire(service: 'mcp.psr17_factory')]
        private readonly StreamFactoryInterface $streamFactory,

        #[Autowire(env: 'csv:MCP_ALLOWED_HOSTS')]
        private readonly array $allowedHosts,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $transport = new StreamableHttpTransport(
            $this->httpMessageFactory->createRequest($request),
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
            [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts),
                new ProtocolVersionMiddleware(),
            ],
        );

        $psrResponse = $this->server->run($transport);
        $streamed = 'text/event-stream' === strtolower($psrResponse->getHeaderLine('Content-Type'));

        return $this->httpFoundationFactory->createResponse($psrResponse, $streamed);
    }
}
