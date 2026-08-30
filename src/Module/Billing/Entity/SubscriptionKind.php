<?php

declare(strict_types=1);

namespace App\Module\Billing\Entity;

enum SubscriptionKind: string
{
    case Trial = 'trial';
    case Stripe = 'stripe';
    case Comp = 'comp';
}
