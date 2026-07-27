<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generates absolute URLs from the app's pinned DEFAULT_URI host instead of
 * the current request's Host header. `framework.yaml` trusts
 * `x-forwarded-host` (needed for the reverse-proxy setup), so within a normal
 * HTTP request the router's context — and therefore plain UrlGeneratorInterface
 * output — follows whatever Host a request carries. For links embedded in
 * security-sensitive email (password reset, account deletion) that must not
 * be redirectable to an attacker-controlled domain via a forged header.
 */
final readonly class PinnedUrlGenerator
{
    public function __construct(
        private RouterInterface $router,

        // Same container parameter framework.router.default_uri (DEFAULT_URI)
        // feeds — see config/packages/routing.yaml.
        #[Autowire(param: 'router.request_context.base_url')]
        private string $defaultUri,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $route, array $parameters = []): string
    {
        $liveContext = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));

        try {
            return $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($liveContext);
        }
    }
}
