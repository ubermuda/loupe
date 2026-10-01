<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SaveBoardAutomationSettingsHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $this->project = $this->makeProject('save-automation');
    }

    public function test_it_stores_and_audits_the_sync_setting(): void
    {
        $this->save(enabled: true, syncBehind: true);

        $settings = $this->stored();
        self::assertTrue($settings->syncBehind);
        self::assertTrue($this->audit->record('board.automation_settings_saved')->context['syncBehind']);
    }

    /** @return iterable<string, array{?array{bool, bool}, bool, bool, int}> */
    public static function transitions(): iterable
    {
        yield 'no row, then the sync on' => [null, true, true, 1];
        yield 'the sync turns on' => [[true, false], true, true, 1];
        yield 'the automation turns on with the sync on' => [[false, true], true, true, 1];
        yield 'the sync stays on' => [[true, true], true, true, 0];
        yield 'the sync turns on with the automation off' => [[false, false], false, true, 0];
        yield 'the sync turns off' => [[true, true], true, false, 0];
        yield 'no row, and the sync off' => [null, true, false, 0];
    }

    /** @param ?array{bool, bool} $before the enabled and syncBehind values stored before the save */
    #[DataProvider('transitions')]
    public function test_turning_the_sync_on_queues_one_pass(?array $before, bool $enabled, bool $syncBehind, int $passes): void
    {
        if (null !== $before) {
            $settings = new BoardAutomationSettings($this->project, enabled: $before[0]);
            $settings->syncBehind = $before[1];
            $this->em->persist($settings);
            $this->em->flush();
        }

        $this->save($enabled, $syncBehind);

        $messages = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport->getSent()),
            static fn (object $message): bool => $message instanceof SyncNextPullRequest,
        ));
        self::assertCount($passes, $messages);
        foreach ($messages as $message) {
            self::assertInstanceOf(SyncNextPullRequest::class, $message);
            self::assertEquals($this->project->id, $message->projectId);
        }
    }

    private function save(bool $enabled, bool $syncBehind): void
    {
        $handler = self::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $handler);
        $handler(new SaveBoardAutomationSettingsCommand(
            project: $this->project,
            enabled: $enabled,
            mergeStrategy: BoardMergeStrategy::Worker,
            fixStrategy: BoardFixStrategy::Fresh,
            loopLimit: 3,
            commentOnFixQueued: false,
            syncBehind: $syncBehind,
        ));
    }

    private function stored(): BoardAutomationSettings
    {
        $repository = self::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $settings = $repository->findOneByProject($this->project);
        self::assertNotNull($settings);
        $this->em->refresh($settings);

        return $settings;
    }
}
