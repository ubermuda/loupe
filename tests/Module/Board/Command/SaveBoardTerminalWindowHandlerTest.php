<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Board\Command\SaveBoardTerminalWindowCommand;
use App\Module\Board\Command\SaveBoardTerminalWindowHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Event\BoardColumnsChanged;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SaveBoardTerminalWindowHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private RecordingAuditor $audit;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $this->project = $this->makeProject('save-terminal-window');
    }

    public function test_it_stores_the_window_of_a_project_with_no_settings_and_audits_it(): void
    {
        $this->save(10);

        self::assertSame(10, $this->stored()->terminalWindowDays);
        $record = $this->audit->record('board.terminal_window_saved');
        self::assertSame((string) $this->project->id, $record->context['projectId']);
        self::assertSame(10, $record->context['terminalWindowDays']);
    }

    public function test_it_tells_the_open_boards_of_the_project_to_reload(): void
    {
        $changed = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardColumnsChanged::class, static function (BoardColumnsChanged $event) use (&$changed): void {
            $changed[] = $event->project;
        });

        $this->save(10);

        self::assertSame([$this->project], $changed);
    }

    public function test_it_keeps_the_other_settings_of_the_project(): void
    {
        $settings = new BoardAutomationSettings($this->project, enabled: false, mergeStrategy: BoardMergeStrategy::Off, loopLimit: 7);
        $this->em->persist($settings);
        $this->em->flush();

        $this->save(1);

        $stored = $this->stored();
        self::assertSame(1, $stored->terminalWindowDays);
        self::assertFalse($stored->enabled);
        self::assertSame(BoardMergeStrategy::Off, $stored->mergeStrategy);
        self::assertSame(7, $stored->loopLimit);
    }

    private function save(int $days): void
    {
        $handler = self::getContainer()->get(SaveBoardTerminalWindowHandler::class);
        self::assertInstanceOf(SaveBoardTerminalWindowHandler::class, $handler);
        $handler(new SaveBoardTerminalWindowCommand($this->project, $days));
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
