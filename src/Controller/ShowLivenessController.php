<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Answers "is this container running PHP" and deliberately nothing else. It
 * reads no database, renders no template and resolves no user.
 *
 * That is the whole point: /healthz fails closed when the database is
 * unreachable, which is correct once an instance is running but deadlocks a
 * first deploy — the database is not reachable until the app is healthy, and
 * the app is not healthy until the database is reachable. A platform health
 * check pointed here breaks that cycle; move it to /healthz afterwards.
 *
 * Its firewall entry in security.yaml is `security: false`, so no
 * authentication listener runs on this path and the "reads nothing" property
 * holds even for a caller that presents a session cookie.
 */
#[Route(
    '/livez',
    name: 'app_livez',
    methods: ['GET'],
)]
final class ShowLivenessController extends AppController
{
    public function __invoke(): Response
    {
        return new Response('live', Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // A cached liveness answer describes a container that may since
            // have died, which is the one thing a probe must never do.
            'Cache-Control' => 'no-store',
        ]);
    }
}
