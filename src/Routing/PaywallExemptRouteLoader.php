<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Route;

/**
 * Extends the framework's own attribute route loader (the "routing.loader.attribute"
 * service, wired to this class in config/services.yaml) so a controller class
 * or action carrying #[PaywallExempt] gets an extra `_paywallExempt` route
 * default alongside the usual `_controller` one. RequireSubscriptionListener
 * reads that default instead of matching against a hard-coded route-name list.
 */
final class PaywallExemptRouteLoader extends AttributeRouteControllerLoader
{
    /**
     * @param \ReflectionClass<object> $class
     */
    #[\Override]
    protected function configureRoute(Route $route, \ReflectionClass $class, \ReflectionMethod $method, object $attr): void
    {
        parent::configureRoute($route, $class, $method, $attr);

        if ([] !== $class->getAttributes(PaywallExempt::class) || [] !== $method->getAttributes(PaywallExempt::class)) {
            $route->setDefault('_paywallExempt', true);
        }
    }
}
