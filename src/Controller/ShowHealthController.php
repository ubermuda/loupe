<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\CheckDatabaseHealthCommand;
use App\Command\CheckDatabaseHealthHandler;
use App\Service\BuildIdentity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness endpoint for load balancers and uptime probes: it answers "can this
 * container serve a request that reaches the database", nothing more.
 *
 * Unauthenticated on purpose — a load balancer has no credentials — which is
 * why the body is a fixed two-state string. Everything an operator would find
 * useful here is something an anonymous caller must not learn: the failing
 * exception (its message carries the database host and user), whether any
 * account exists yet. Those belong on the authenticated system-status page.
 *
 * The build version is the one exception, and only to a caller presenting
 * HEALTH_PROBE_TOKEN — so a post-deploy check can prove which build went live
 * without a session. Unset, the field never appears, which is the default a
 * self-hosted instance inherits: it must not advertise its build to anyone who
 * asks.
 */
#[Route(
    '/healthz',
    name: 'app_healthz',
    methods: ['GET'],
)]
final class ShowHealthController extends AppController
{
    public function __construct(
        private readonly CheckDatabaseHealthHandler $checkDatabaseHealth,
        private readonly BuildIdentity $build,

        #[Autowire(env: 'HEALTH_PROBE_TOKEN')]
        private readonly string $probeToken = '',
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $healthy = ($this->checkDatabaseHealth)(new CheckDatabaseHealthCommand())->healthy;

        $payload = ['status' => $healthy ? 'ok' : 'error'];

        // Header, never a query parameter: those are recorded in access logs
        // and forwarded in Referer. hash_equals because the comparison is
        // against a secret.
        if ('' !== $this->probeToken
            && hash_equals($this->probeToken, (string) $request->headers->get('X-Probe-Token', ''))) {
            $payload['version'] = $this->build->version;
        }

        $response = new JsonResponse(
            $payload,
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
        // A cached health check reports the state of a container that may since
        // have died, which is the one thing a probe must never do.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
