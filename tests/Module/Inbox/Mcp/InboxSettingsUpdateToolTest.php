<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Mcp\InboxSettingsUpdateTool;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class InboxSettingsUpdateToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxSettingsUpdateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(InboxSettingsUpdateTool::class);
        self::assertInstanceOf(InboxSettingsUpdateTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-settings-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)(runBlocked: false);
    }

    public function test_given_switches_change_and_omitted_ones_keep_their_value(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-settings-update');
        $stored = new InboxProjectSettings($project);
        $stored->documentInReview = false;
        $this->em->persist($stored);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $result = ($this->tool)(runBlocked: false, cardPaused: false);

        self::assertSame([
            'documentInReview' => false,
            'runBlocked' => false,
            'runGaveUp' => true,
            'runWaitingForPerson' => true,
            'pullRequestReady' => true,
            'pullRequestFixStopped' => true,
            'cardPaused' => false,
        ], $result);

        $this->em->clear();
        $repository = self::getContainer()->get(InboxProjectSettingsRepository::class);
        self::assertInstanceOf(InboxProjectSettingsRepository::class, $repository);
        $rows = $repository->findBy(['project' => (string) $project->id]);
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->documentInReview);
        self::assertFalse($rows[0]->runBlocked);
        self::assertTrue($rows[0]->runGaveUp);
        self::assertFalse($rows[0]->cardPaused);
        self::assertEquals(
            [new ReconcileCardWaits((string) $project->id, null)],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }

    public function test_a_project_with_no_stored_settings_starts_from_every_switch_on(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-settings-defaults'));

        $result = ($this->tool)(pullRequestReady: false);

        self::assertFalse($result['pullRequestReady']);
        self::assertTrue($result['documentInReview']);
        self::assertTrue($result['runGaveUp']);
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-settings-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)(runBlocked: false);
    }
}
