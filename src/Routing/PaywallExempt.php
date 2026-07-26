<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Marks a controller (or, on a multi-action controller, a single action
 * method) as reachable by a user the billing paywall would otherwise block —
 * the paywall itself, auth, verification, data export, and account deletion.
 *
 * Read by PaywallExemptRouteLoader at route-compile time and surfaced as the
 * `_paywallExempt` route default that RequireSubscriptionListener checks, so
 * the exemption travels with the route definition instead of living in a
 * separate hard-coded route-name list that silently stops protecting a route
 * the moment it is renamed.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class PaywallExempt
{
}
