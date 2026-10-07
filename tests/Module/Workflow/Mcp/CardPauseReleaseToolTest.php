<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mcp;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\AdvertisedTools;
use App\Module\Workflow\Mcp\CardPauseReleaseTool;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\McpTokenScenario;
use Mcp\Capability\Registry;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardPauseReleaseToolTest extends KernelTestCase
{
    use McpTokenScenario;
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('mcp-pause-release');
        $this->bindLifecycle($this->project);
        $this->actAsMcpTokenBoundTo($this->project);
    }

    public function test_a_release_by_number_ends_the_pause_and_names_the_agent(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Retries);

        $result = $this->tool()(number: $card->number);

        self::assertSame([
            'cardId' => (string) $card->id,
            'outcome' => 'released',
            'kind' => 'retries',
            'reason' => 'move-refused',
            'ruleId' => 'tech-design-write',
        ], $result);
        self::assertNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
        self::assertSame(['agent'], $this->em()->getConnection()->fetchFirstColumn(
            "SELECT actor_kind FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'",
            [(string) $card->id],
        ));
    }

    public function test_a_release_by_card_id_with_the_active_pause_id_ends_the_pause(): void
    {
        $card = $this->card($this->project);
        $pause = $this->pause($card, CardPauseKind::WorkLimit);

        $result = $this->tool()((string) $card->id, pauseId: (string) $pause->id);

        self::assertSame('released', $result['outcome']);
        self::assertSame('work-limit', $result['kind'] ?? null);
    }

    public function test_a_card_with_no_pause_is_refused_as_not_paused(): void
    {
        $card = $this->card($this->project);

        self::assertSame([
            'cardId' => (string) $card->id,
            'outcome' => 'refused',
            'code' => 'not-paused',
            'message' => 'The card is not paused.',
        ], $this->tool()((string) $card->id));
    }

    public function test_a_held_card_is_refused_as_unmanaged(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Retries);
        $this->service(CardHolds::class)->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        self::assertSame('card-unmanaged', $this->tool()((string) $card->id)['code'] ?? null);
    }

    public function test_a_card_whose_automation_is_off_is_refused_as_unmanaged(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Retries);
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        self::assertSame('card-unmanaged', $this->tool()((string) $card->id)['code'] ?? null);
    }

    public function test_a_pause_id_that_is_not_the_active_pause_is_refused_as_changed(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Retries);

        self::assertSame('pause-changed', $this->tool()((string) $card->id, pauseId: (string) Uuid::v7())['code'] ?? null);
        self::assertNotNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
    }

    public function test_a_rule_pause_is_refused_as_not_releasable(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Rule);

        self::assertSame('kind-not-releasable', $this->tool()((string) $card->id)['code'] ?? null);
        self::assertNotNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
    }

    public function test_an_unknown_card_is_not_found(): void
    {
        $unknown = (string) Uuid::v7();

        self::assertSame([
            'cardId' => $unknown,
            'outcome' => 'refused',
            'code' => 'not-found',
            'message' => \sprintf('Card "%s" not found or not accessible.', $unknown),
        ], $this->tool()($unknown));
        self::assertSame([
            'cardId' => null,
            'outcome' => 'refused',
            'code' => 'not-found',
            'message' => 'This project has no card 99.',
        ], $this->tool()(number: 99));
    }

    public function test_a_card_of_another_project_is_not_found(): void
    {
        $other = $this->workflowProject('mcp-pause-release-other');
        $this->bindLifecycle($other);
        $foreign = $this->card($other);
        $this->pause($foreign, CardPauseKind::Retries);

        self::assertSame('not-found', $this->tool()((string) $foreign->id)['code'] ?? null);
        self::assertNotNull($this->service(CardPauseRepository::class)->findActiveForCard($foreign));
    }

    public function test_a_malformed_pause_id_is_refused(): void
    {
        $card = $this->card($this->project);
        $this->pause($card, CardPauseKind::Retries);

        try {
            $this->tool()((string) $card->id, pauseId: 'pause-1');
            self::fail('Expected a refusal.');
        } catch (ToolCallException $e) {
            self::assertSame('"pause-1" is not a valid pause ID. Pass the pauseId of the pause of card_get.', $e->getMessage());
        }
        self::assertNotNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
    }

    public function test_both_card_arguments_are_refused(): void
    {
        $card = $this->card($this->project);

        $this->expectExceptionObject(new ToolCallException('Pass cardId or number, not both.'));

        $this->tool()((string) $card->id, $card->number);
    }

    public function test_the_tool_is_published_after_card_release(): void
    {
        self::assertInstanceOf(Server::class, self::getContainer()->get('mcp.server'));
        $registry = $this->service(Registry::class, 'mcp.registry');
        $schema = $registry->getTool(CardPauseReleaseTool::NAME)->tool->inputSchema;
        self::assertSame([], $schema['required'] ?? []);
        self::assertSame(1, $schema['properties']['number']['minimum']);
        self::assertArrayHasKey('pauseId', $schema['properties']);

        $names = array_column($this->service(AdvertisedTools::class)->enabled(), 'name');
        $release = array_search('card_release', $names, true);
        self::assertIsInt($release);
        self::assertSame(CardPauseReleaseTool::NAME, $names[$release + 1] ?? null);
    }

    public function test_the_tool_has_a_connect_page_description(): void
    {
        $key = 'project.connect.tool.'.CardPauseReleaseTool::NAME;

        self::assertNotSame($key, $this->service(TranslatorInterface::class)->trans($key));
    }

    private function tool(): CardPauseReleaseTool
    {
        return $this->service(CardPauseReleaseTool::class);
    }

    private function card(Project $project): Card
    {
        return $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $project,
            title: 'Card',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($project, 'tech-design'),
            reporter: CardReporter::Human,
        ));
    }

    private function pause(Card $card, CardPauseKind $kind): CardPause
    {
        return $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'move-refused', 'tech-design-write', $kind))
            ?? throw new \LogicException('The card had no pause.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class, ?string $id = null): object
    {
        $service = self::getContainer()->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
