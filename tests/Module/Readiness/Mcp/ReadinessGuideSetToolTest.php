<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Mcp;

use App\Module\Readiness\Mcp\ReadinessGuideSetTool;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReadinessGuideSetToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use ReadinessScenario;

    private ReadinessGuideSetTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $tool = self::getContainer()->get(ReadinessGuideSetTool::class);
        self::assertInstanceOf(ReadinessGuideSetTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_hiding_the_guide_stores_the_time_it_was_hidden(): void
    {
        $project = $this->project($this->user('guide-hide@example.test'), 'Guide hide');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(shown: false);

        self::assertFalse($result['shown']);
        $hiddenAt = $this->reload($project)->readinessGuideHiddenAt;
        self::assertNotNull($hiddenAt);
        self::assertSame($hiddenAt->format(\DATE_ATOM), $result['hiddenAt']);
    }

    public function test_showing_the_guide_clears_the_time_it_was_hidden(): void
    {
        $project = $this->project($this->user('guide-show@example.test'), 'Guide show');
        $project->readinessGuideHiddenAt = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');
        $this->em()->flush();
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['shown' => true, 'hiddenAt' => null], ($this->tool)(shown: true));
        self::assertNull($this->reload($project)->readinessGuideHiddenAt);
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->actAsUnboundMcpToken($this->user('guide-unbound@example.test'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)(shown: false);
    }
}
