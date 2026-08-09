<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the default Mercure hub an HTTP client with a bounded timeout.
 *
 * A review submit publishes to the hub inline, before the visitor's response, so
 * with the shared `http_client` a hung hub adds its full default timeout to
 * every submit. symfony/mercure-bundle offers no per-hub `http_client` option —
 * it hardcodes a reference to the generic client as the hub's fifth constructor
 * argument — so the reference is replaced here instead of configured.
 *
 * Deliberately narrow: lowering the timeout on the shared client would also
 * bound Stripe and the OAuth providers, which are request/response calls that
 * legitimately take longer than a fire-and-forget nudge.
 */
final class MercureHubTimeoutPass implements CompilerPassInterface
{
    private const string HUB_ID = 'mercure.hub.default';
    private const string CLIENT_ID = 'mercure.hub_client';
    private const int HTTP_CLIENT_ARGUMENT = 4;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        // Both are absent when Mercure is off, and the hub is a FrankenPhpHub
        // with no HTTP client when the built-in hub is configured. has() rather
        // than hasDefinition() for the client: a scoped client's plain name is an
        // alias onto its UriTemplate decorator, and hasDefinition() misses it.
        if (!$container->hasDefinition(self::HUB_ID) || !$container->has(self::CLIENT_ID)) {
            return;
        }

        $hub = $container->getDefinition(self::HUB_ID);
        if (\count($hub->getArguments()) <= self::HTTP_CLIENT_ARGUMENT) {
            return;
        }

        $hub->replaceArgument(self::HTTP_CLIENT_ARGUMENT, new Reference(self::CLIENT_ID));
    }
}
