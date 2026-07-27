<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the class of FrameworkBundle's own "routing.loader.attribute" service
 * for PaywallExemptRouteLoader, a subclass, leaving its existing constructor
 * argument and "routing.loader" tag/priority (both declared by FrameworkBundle
 * itself) untouched — mutating the Definition in place rather than redeclaring
 * it in config/services.yaml, where the same id would completely *replace*
 * the original, forcing that argument/tag to be repeated by hand.
 */
final class PaywallExemptRouteLoaderPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('routing.loader.attribute')) {
            return;
        }

        $container->getDefinition('routing.loader.attribute')->setClass(PaywallExemptRouteLoader::class);
    }
}
