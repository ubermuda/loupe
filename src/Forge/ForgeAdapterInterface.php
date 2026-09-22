<?php

declare(strict_types=1);

namespace App\Forge;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * One forge's half of the receiver, and the only place that knows a forge exists.
 *
 * An adapter does three things. It verifies the delivery, it decides whether the
 * delivery is a signal the lifecycle uses, and it says so in the neutral
 * vocabulary. Anything else it does is a leak.
 *
 * Verification lives here rather than in a shared helper because no two forges
 * agree on it. GitHub signs the body with an HMAC, and GitLab sends a plain
 * shared token in a header.
 */
#[AutoconfigureTag('app.forge_adapter')]
interface ForgeAdapterInterface
{
    /** @return non-empty-string the slug its deliveries arrive under, such as `github` */
    public function forge(): string;

    /**
     * @return list<ForgeDelivery> empty when the delivery carries no signal the
     *                             lifecycle uses, which is the common case
     *
     * @throws InvalidForgeSignature when the delivery is not from this forge
     */
    public function translate(Request $request): array;
}
