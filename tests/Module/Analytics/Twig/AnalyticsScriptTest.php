<?php

declare(strict_types=1);

namespace App\Tests\Module\Analytics\Twig;

use App\Module\Analytics\Twig\AnalyticsScript;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Both gates must agree before anything is sent to a third party: configuration
 * says an instance may, the flag says it currently does.
 */
final class AnalyticsScriptTest extends TestCase
{
    public function test_nothing_is_emitted_without_configuration(): void
    {
        self::assertNull($this->script(flagEnabled: true, scriptUrl: '', websiteId: '')->snippet());
    }

    public function test_nothing_is_emitted_with_only_half_the_configuration(): void
    {
        self::assertNull($this->script(flagEnabled: true, scriptUrl: 'https://a.test/s.js', websiteId: '')->snippet());
        self::assertNull($this->script(flagEnabled: true, scriptUrl: '', websiteId: 'abc')->snippet());
    }

    public function test_nothing_is_emitted_while_the_flag_is_off(): void
    {
        // The default a fresh install carries, so configuration alone must not
        // start sending.
        self::assertNull($this->script(flagEnabled: false, scriptUrl: 'https://a.test/s.js', websiteId: 'abc')->snippet());
    }

    public function test_both_gates_open_emits_the_tag(): void
    {
        self::assertSame(
            ['src' => 'https://a.test/s.js', 'websiteId' => 'abc'],
            $this->script(flagEnabled: true, scriptUrl: 'https://a.test/s.js', websiteId: 'abc')->snippet(),
        );
    }

    private function script(bool $flagEnabled, string $scriptUrl, string $websiteId): AnalyticsScript
    {
        /** @var FeatureFlagService&Stub $flags */
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($flagEnabled);

        return new AnalyticsScript($flags, $scriptUrl, $websiteId);
    }
}
