<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Event\BoardAutomationSettingsSaved;
use App\Module\Board\Messenger\SettleSiteReviewChecks;
use App\Module\Board\Messenger\SyncNextPullRequest;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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

    public function test_both_widget_verdict_opt_ins_start_off_and_are_stored_and_audited(): void
    {
        self::assertFalse((new BoardAutomationSettings($this->project))->postWidgetReviews);
        self::assertFalse((new BoardAutomationSettings($this->project))->siteReviewCheck);

        $this->save(enabled: true, syncBehind: false, postWidgetReviews: true, siteReviewCheck: true);

        $settings = $this->stored();
        self::assertTrue($settings->postWidgetReviews);
        self::assertTrue($settings->siteReviewCheck);
        $context = $this->audit->record('board.automation_settings_saved')->context;
        self::assertTrue($context['postWidgetReviews']);
        self::assertTrue($context['siteReviewCheck']);

        $this->save(enabled: true, syncBehind: false);
        self::assertFalse($this->stored()->postWidgetReviews);
        self::assertFalse($this->stored()->siteReviewCheck);
    }

    public function test_it_stores_and_audits_the_stale_approval_comment_setting(): void
    {
        $this->save(enabled: true, syncBehind: false, commentOnStaleApproval: true);

        self::assertTrue($this->stored()->commentOnStaleApproval);
        self::assertTrue($this->audit->record('board.automation_settings_saved')->context['commentOnStaleApproval']);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function writeOptIns(): iterable
    {
        yield 'merge on' => [true, false];
        yield 'base change on' => [false, true];
    }

    #[DataProvider('writeOptIns')]
    public function test_it_stores_and_audits_the_write_opt_ins(bool $mergePullRequests, bool $changeBase): void
    {
        $this->save(enabled: true, syncBehind: false, mergePullRequests: $mergePullRequests, changeBase: $changeBase);

        $settings = $this->stored();
        self::assertSame($mergePullRequests, $settings->mergePullRequests);
        self::assertSame($changeBase, $settings->changeBase);
        $context = $this->audit->record('board.automation_settings_saved')->context;
        self::assertSame($mergePullRequests, $context['mergePullRequests']);
        self::assertSame($changeBase, $context['changeBase']);
    }

    public function test_it_stores_and_audits_the_epic_pull_request_settings(): void
    {
        $this->save(enabled: true, syncBehind: false, openEpicPullRequests: true, epicBranchPattern: '  feature/epic-{number}  ');

        $settings = $this->stored();
        self::assertTrue($settings->openEpicPullRequests);
        self::assertSame('feature/epic-{number}', $settings->epicBranchPattern);
        $context = $this->audit->record('board.automation_settings_saved')->context;
        self::assertTrue($context['openEpicPullRequests']);
        self::assertSame('feature/epic-{number}', $context['epicBranchPattern']);
    }

    /** @return iterable<string, array{?string}> */
    public static function blankPatterns(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    #[DataProvider('blankPatterns')]
    public function test_a_blank_epic_branch_pattern_is_stored_as_null(?string $pattern): void
    {
        $this->save(enabled: true, syncBehind: false, epicBranchPattern: $pattern);

        self::assertNull($this->stored()->epicBranchPattern);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedPatterns(): iterable
    {
        yield 'no placeholder' => ['epic/'];
        yield 'the placeholder twice' => ['epic/{number}/{number}'];
        yield 'two dots' => ['epic/../{number}'];
        yield 'a lock suffix' => ['epic/{number}.lock'];
        yield 'too long' => ['epic/{number}'.str_repeat('a', BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH - 12)];
    }

    #[DataProvider('refusedPatterns')]
    public function test_a_pattern_that_is_no_branch_name_with_one_number_is_refused_and_nothing_is_saved(string $pattern): void
    {
        $this->em->persist(new BoardAutomationSettings($this->project, syncBehind: false, epicBranchPattern: 'feature/{number}'));
        $this->em->flush();
        $saved = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(BoardAutomationSettingsSaved::class, static function (BoardAutomationSettingsSaved $event) use (&$saved): void {
            $saved[] = $event;
        });

        try {
            $this->save(enabled: true, syncBehind: true, openEpicPullRequests: true, epicBranchPattern: $pattern);
            self::fail('The handler accepted '.$pattern);
        } catch (DomainErrors $e) {
            self::assertSame(['epicBranchPattern' => 'board.automation.error.epic_branch_pattern_invalid'], $e->errors);
        }

        $settings = $this->stored();
        self::assertSame('feature/{number}', $settings->epicBranchPattern);
        self::assertFalse($settings->syncBehind);
        self::assertFalse($settings->openEpicPullRequests);
        self::assertSame([], $saved);
        self::assertSame([], $this->audit->records('board.automation_settings_saved'));
        self::assertSame([], $this->transport->getSent());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function acceptedPatterns(): iterable
    {
        yield 'the default' => ['epic/{number}', 'epic/{number}'];
        yield 'a prefix and a dash' => ['feature/epic-{number}', 'feature/epic-{number}'];
        yield 'surrounding spaces' => ['  epic/{number}  ', 'epic/{number}'];
        yield 'empty' => ['', null];
        yield 'the longest' => ['epic/{number}'.str_repeat('a', BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH - 13), 'epic/{number}'.str_repeat('a', BoardAutomationSettings::EPIC_BRANCH_PATTERN_MAX_LENGTH - 13)];
    }

    #[DataProvider('acceptedPatterns')]
    public function test_a_valid_or_empty_pattern_is_stored(string $pattern, ?string $expected): void
    {
        $this->save(enabled: true, syncBehind: false, epicBranchPattern: $pattern);

        self::assertSame($expected, $this->stored()->epicBranchPattern);
    }

    public function test_every_write_opt_in_is_off_by_default(): void
    {
        $this->em->persist(new BoardAutomationSettings($this->project));
        $this->em->flush();

        $settings = $this->stored();
        self::assertFalse($settings->mergePullRequests);
        self::assertFalse($settings->changeBase);
        self::assertFalse($settings->epicDraftSwitch);
        self::assertFalse($settings->closeEpicPullRequests);
        self::assertFalse($settings->openEpicPullRequests);
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

    public function test_only_turning_the_site_review_check_off_queues_a_settle(): void
    {
        $this->save(enabled: true, syncBehind: false, siteReviewCheck: true);
        $this->save(enabled: true, syncBehind: false, siteReviewCheck: true);
        self::assertSame([], $this->settles());

        $this->save(enabled: true, syncBehind: false);
        $this->save(enabled: true, syncBehind: false);

        $settles = $this->settles();
        self::assertCount(1, $settles);
        self::assertEquals($this->project->id, $settles[0]->projectId);
    }

    public function test_turning_the_automation_off_queues_a_settle_while_the_check_stays_on(): void
    {
        $this->save(enabled: true, syncBehind: false, siteReviewCheck: true);
        $this->save(enabled: false, syncBehind: false, siteReviewCheck: true);
        $this->save(enabled: false, syncBehind: false, siteReviewCheck: true);
        $this->save(enabled: true, syncBehind: false, siteReviewCheck: true);

        $settles = $this->settles();
        self::assertCount(1, $settles);
        self::assertEquals($this->project->id, $settles[0]->projectId);
    }

    /** @return list<SettleSiteReviewChecks> */
    private function settles(): array
    {
        $settles = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SettleSiteReviewChecks) {
                $settles[] = $message;
            }
        }

        return $settles;
    }

    private function save(bool $enabled, bool $syncBehind, bool $commentOnStaleApproval = false, bool $mergePullRequests = false, bool $changeBase = false, bool $openEpicPullRequests = false, ?string $epicBranchPattern = 'epic/{number}', bool $postWidgetReviews = false, bool $siteReviewCheck = false): void
    {
        $handler = self::getContainer()->get(SaveBoardAutomationSettingsHandler::class);
        self::assertInstanceOf(SaveBoardAutomationSettingsHandler::class, $handler);
        $handler(new SaveBoardAutomationSettingsCommand(
            project: $this->project,
            enabled: $enabled,
            commentOnFixQueued: false,
            commentOnStaleApproval: $commentOnStaleApproval,
            syncBehind: $syncBehind,
            mergePullRequests: $mergePullRequests,
            changeBase: $changeBase,
            postWidgetReviews: $postWidgetReviews,
            siteReviewCheck: $siteReviewCheck,
            openEpicPullRequests: $openEpicPullRequests,
            epicBranchPattern: $epicBranchPattern,
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
