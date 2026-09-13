<?php

declare(strict_types=1);

namespace App\Mercure;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Decides whether the current user may subscribe to a Mercure topic that its
 * module owns. A topic no authorizer claims never enters a subscriber token.
 */
#[AutoconfigureTag(self::TAG)]
interface MercureTopicAuthorizerInterface
{
    public const string TAG = 'app.mercure_topic_authorizer';

    /** Null when this authorizer does not own the topic. */
    public function mayCurrentUserSubscribe(string $topic): ?bool;
}
