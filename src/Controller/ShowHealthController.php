<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness endpoint for load balancers and uptime probes: it answers "can this
 * container serve a request that reaches the database", nothing more.
 *
 * Unauthenticated on purpose — a load balancer has no credentials — which is
 * why the body is a fixed two-state string. Everything an operator would find
 * useful here is something an anonymous caller must not learn: the failing
 * exception (its message carries the database host and user), the framework or
 * application version, whether any account exists yet. Those belong on the
 * authenticated system-status page instead.
 */
#[Route(
    '/healthz',
    name: 'app_healthz',
    methods: ['GET'],
)]
final class ShowHealthController extends AppController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1'); // @translation-check-ignore
            $healthy = true;
        } catch (\Throwable $e) {
            $this->logger->error('health.database_unreachable', ['exception' => $e]);
            $healthy = false;
        }

        $response = new JsonResponse(
            ['status' => $healthy ? 'ok' : 'error'],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        // A cached health check reports the state of a container that may since
        // have died, which is the one thing a probe must never do.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
