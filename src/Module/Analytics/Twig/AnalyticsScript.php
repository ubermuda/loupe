<?php

declare(strict_types=1);

namespace App\Module\Analytics\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Whether to emit an analytics tag, and what to put in it.
 *
 * Two gates, deliberately. The flag is the operator's decision and lives in the
 * database, so it can be turned off without a deploy. The script URL is
 * deployment configuration and lives in the environment, so an instance that
 * never sets one cannot be switched on by accident. Either being absent means
 * no tag and no third-party origin in the page.
 */
final class AnalyticsScript extends AbstractExtension
{
    public const string ENABLED_FLAG = 'analytics.enabled';

    public function __construct(
        private readonly FeatureFlagService $featureFlags,

        #[Autowire(env: 'ANALYTICS_SCRIPT_URL')]
        private readonly string $scriptUrl,

        #[Autowire(env: 'ANALYTICS_WEBSITE_ID')]
        private readonly string $websiteId,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('analytics_script', $this->snippet(...)),
        ];
    }

    /**
     * @return array{src: string, websiteId: string}|null
     */
    public function snippet(): ?array
    {
        if ('' === $this->scriptUrl || '' === $this->websiteId) {
            return null;
        }

        if (!$this->featureFlags->isEnabled(self::ENABLED_FLAG)) {
            return null;
        }

        return ['src' => $this->scriptUrl, 'websiteId' => $this->websiteId];
    }
}
