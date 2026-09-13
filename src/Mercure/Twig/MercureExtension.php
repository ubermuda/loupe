<?php

declare(strict_types=1);

namespace App\Mercure\Twig;

use App\Mercure\MercureSubscriptions;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A template asks for a topic with mercure_subscribe(). The layout reads the
 * allowed topics after the page body has rendered, so it sees every request.
 */
final class MercureExtension extends AbstractExtension
{
    public function __construct(
        private readonly MercureSubscriptions $subscriptions,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('mercure_subscribe', $this->subscriptions->request(...)),
            new TwigFunction('mercure_allowed_topics', $this->subscriptions->allowedTopics(...)),
            new TwigFunction('mercure_hub_url', fn (): string => $this->subscriptions->hubUrl),
        ];
    }
}
