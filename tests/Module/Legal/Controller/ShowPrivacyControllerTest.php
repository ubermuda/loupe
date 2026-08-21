<?php

declare(strict_types=1);

namespace App\Tests\Module\Legal\Controller;

use App\Module\Analytics\Twig\AnalyticsScript;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The policy has to describe the instance it is served from: an instance that
 * sends page views somewhere may not deny third-party tracking, and one that
 * sends none may not name a processor it does not use.
 */
final class ShowPrivacyControllerTest extends WebTestCase
{
    private const string HOST = 'analytics.test';

    #[\Override]
    protected function tearDown(): void
    {
        $this->setAnalyticsEnv('', '');

        parent::tearDown();
    }

    public function test_the_policy_denies_third_party_tracking_while_analytics_is_off(): void
    {
        $this->setAnalyticsEnv('', '');
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertResponseIsSuccessful();
        // Nothing is emitted, so nothing may be claimed: the guard that keeps
        // the assertions below from passing on an unconfigured page.
        self::assertSelectorNotExists('script[data-website-id]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            'We use no analytics cookies, no advertising cookies and no third-party tracking.',
            $html,
        );
        self::assertStringNotContainsString('Page-view analytics', $html);
    }

    public function test_the_policy_names_the_analytics_host_while_analytics_is_on(): void
    {
        $this->setAnalyticsEnv('https://'.self::HOST.'/script.js', 'website-id');
        $client = static::createClient();
        $this->enableAnalyticsFlag($client);

        $client->request(Request::METHOD_GET, '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('script[data-website-id="website-id"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('no third-party tracking', $html);
        self::assertStringContainsString(
            'Page views are counted by an analytics service at '.self::HOST.', which sets no cookies',
            $html,
        );
        self::assertStringContainsString('<td>'.self::HOST.'</td>', $html);
        self::assertStringContainsString('<td>Page-view analytics</td>', $html);
    }

    private function setAnalyticsEnv(string $scriptUrl, string $websiteId): void
    {
        // Autowired as %env(...)%, so these are read when the extension is
        // instantiated during the request rather than at compile time.
        $_ENV['ANALYTICS_SCRIPT_URL'] = $_SERVER['ANALYTICS_SCRIPT_URL'] = $scriptUrl;
        $_ENV['ANALYTICS_WEBSITE_ID'] = $_SERVER['ANALYTICS_WEBSITE_ID'] = $websiteId;
    }

    /** The flag is seeded at install, not by a migration, so the test owns the row. */
    private function enableAnalyticsFlag(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $em->persist(new FeatureFlag(
            name: AnalyticsScript::ENABLED_FLAG,
            type: FeatureFlagType::Bool,
            value: true,
        ));
        $em->flush();
    }
}
