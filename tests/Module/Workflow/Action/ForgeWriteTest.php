<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBaseChangers;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestMergers;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\ActionType;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class ForgeWriteTest extends KernelTestCase
{
    use ActionScenario;

    private FakeForgeWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->writer = new FakeForgeWriter();
    }

    public function test_a_card_with_no_pull_request_is_refused(): void
    {
        $card = $this->card($this->project(mergePullRequests: true), 'in-review');

        self::assertEquals(ActionOutcome::refused('no-pull-request'), $this->write($card, 'merge'));
        self::assertSame([], $this->writer->calls);
    }

    public function test_an_opt_in_that_is_off_opens_the_fallback_work(): void
    {
        $card = $this->card($this->project(), 'in-review');
        $this->pullRequest($card);

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'merge', fallback: 'merge'));

        self::assertSame([], $this->writer->calls);
        self::assertSame(['merge'], $this->liveKinds($card));
    }

    public function test_it_merges_the_primary_pull_request_with_the_configured_method(): void
    {
        $card = $this->card($this->project(mergePullRequests: true), 'in-review');
        $pullRequest = $this->pullRequest($card, headSha: 'abc123');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'merge'));

        self::assertSame([['merge', $pullRequest->number, 'squash', 'abc123']], $this->writer->calls);
        self::assertSame([], $this->liveKinds($card));
    }

    public function test_a_forge_with_no_merger_opens_the_fallback_work(): void
    {
        $card = $this->card($this->project(mergePullRequests: true, changeBase: true), 'in-review');
        $this->pullRequest($card, base: 'parent');
        $this->pullRequest($this->card($card->project, 'done'), state: PullRequestState::Merged, base: 'main', head: 'parent');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'merge', fallback: 'merge', writers: false));
        self::assertEquals(ActionOutcome::done(), $this->write($card, 'change-base', fallback: 'rebase-stacked', writers: false));

        self::assertSame(['merge', 'rebase-stacked'], $this->liveKinds($card));
    }

    public function test_a_failed_write_is_refused_with_its_cause(): void
    {
        $card = $this->card($this->project(mergePullRequests: true), 'in-review');
        $this->pullRequest($card);
        $this->writer->failure = new PullRequestWriteFailed('rate_limited', permanent: false);

        self::assertEquals(ActionOutcome::refused('rate-limited'), $this->write($card, 'merge'));
        self::assertSame([], $this->liveKinds($card));
    }

    public function test_it_moves_the_base_to_the_base_of_the_merged_parent(): void
    {
        $card = $this->card($this->project(changeBase: true), 'in-review');
        $pullRequest = $this->pullRequest($card, base: 'parent');
        $this->pullRequest($this->card($card->project, 'done'), state: PullRequestState::Merged, base: 'release', head: 'parent');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'change-base'));

        self::assertSame([['changeBase', $pullRequest->number, 'release']], $this->writer->calls);
    }

    public function test_a_base_with_no_merged_parent_is_refused(): void
    {
        $card = $this->card($this->project(changeBase: true), 'in-review');
        $this->pullRequest($card, base: 'parent');
        $this->pullRequest($this->card($card->project, 'in-review'), base: 'main', head: 'parent');

        self::assertEquals(ActionOutcome::refused('no-parent-base'), $this->write($card, 'change-base'));
        self::assertSame([], $this->writer->calls);
    }

    public function test_it_updates_the_branch_from_its_head(): void
    {
        $card = $this->card($this->project(syncBehind: true), 'in-review');
        $pullRequest = $this->pullRequest($card, headSha: 'abc123');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'update-branch'));

        self::assertSame([['update', $pullRequest->number, 'abc123']], $this->writer->calls);
    }

    public function test_a_branch_update_without_an_updater_or_a_head_or_that_fails(): void
    {
        $card = $this->card($this->project(syncBehind: true), 'in-review');
        $pullRequest = $this->pullRequest($card, headSha: null);

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'update-branch', fallback: 'sync', writers: false));
        self::assertSame(['sync'], $this->liveKinds($card));

        self::assertEquals(ActionOutcome::refused('no-head'), $this->write($card, 'update-branch'));

        $pullRequest->headSha = 'abc123';
        $this->writer->failure = new PullRequestSyncFailed('merge_conflict', permanent: true);
        self::assertEquals(ActionOutcome::refused('merge-conflict'), $this->write($card, 'update-branch'));
    }

    /** @param list<bool|int|string> $call */
    #[DataProvider('stateWrites')]
    public function test_a_state_write_asks_the_state_writer(string $write, array $call): void
    {
        $card = $this->card($this->project(epicDraftSwitch: true, closeEpicPullRequests: true), 'in-review');
        $pullRequest = $this->pullRequest($card);

        self::assertEquals(ActionOutcome::done(), $this->write($card, $write));

        self::assertSame([[$call[0], $pullRequest->number, ...\array_slice($call, 1)]], $this->writer->calls);
    }

    /** @return iterable<string, array{string, list<bool|string>}> */
    public static function stateWrites(): iterable
    {
        yield 'draft' => ['draft', ['setDraft', true]];
        yield 'ready' => ['ready', ['setDraft', false]];
        yield 'close' => ['close', ['close']];
    }

    public function test_a_state_write_without_a_writer_or_its_opt_in_opens_the_fallback_work(): void
    {
        $card = $this->card($this->project(epicDraftSwitch: true), 'in-review');
        $this->pullRequest($card);

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'draft', fallback: 'draft-switch', writers: false));
        self::assertEquals(ActionOutcome::done(), $this->write($card, 'close', fallback: 'close-epic'));

        self::assertSame([], $this->writer->calls);
        self::assertSame(['close-epic', 'draft-switch'], $this->liveKinds($card));
    }

    public function test_a_failed_state_write_is_refused_with_its_cause(): void
    {
        $card = $this->card($this->project(closeEpicPullRequests: true), 'in-review');
        $this->pullRequest($card);
        $this->writer->failure = new PullRequestWriteFailed('permission', permanent: true);

        self::assertEquals(ActionOutcome::refused('permission'), $this->write($card, 'close'));
    }

    public function test_a_state_write_writes_every_pull_request_of_the_card(): void
    {
        $card = $this->card($this->project(epicDraftSwitch: true), 'in-review');
        $first = $this->pullRequest($card);
        $second = $this->pullRequest($card, state: PullRequestState::Closed);

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'ready', fallback: null));

        self::assertSame([['setDraft', $first->number, false], ['setDraft', $second->number, false]], $this->writer->calls);
    }

    public function test_a_state_write_on_a_card_with_no_pull_request_is_done(): void
    {
        $card = $this->card($this->project(closeEpicPullRequests: true), 'backlog');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'close', fallback: null));
        self::assertSame([], $this->writer->calls);
    }

    public function test_a_state_write_with_no_fallback_does_nothing_without_its_opt_in_or_a_writer(): void
    {
        $card = $this->card($this->project(epicDraftSwitch: true), 'in-review');
        $this->pullRequest($card);

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'close', fallback: null));
        self::assertEquals(ActionOutcome::done(), $this->write($card, 'draft', fallback: null, writers: false));

        self::assertSame([], $this->writer->calls);
        self::assertSame([], $this->liveKinds($card));
    }

    public function test_a_failed_state_write_still_writes_the_other_pull_requests(): void
    {
        $card = $this->card($this->project(closeEpicPullRequests: true), 'backlog');
        $first = $this->pullRequest($card);
        $second = $this->pullRequest($card);
        $this->writer->failure = new PullRequestWriteFailed('api_failed_rate_limited', permanent: false);
        $this->writer->failingNumbers = [$first->number];

        self::assertEquals(ActionOutcome::refused('api-failed-rate-limited'), $this->write($card, 'close', fallback: null));
        self::assertSame([['close', $first->number], ['close', $second->number]], $this->writer->calls);
    }

    public function test_a_comment_is_refused(): void
    {
        $card = $this->card($this->project(), 'in-review');
        $this->pullRequest($card);

        self::assertEquals(ActionOutcome::refused('unsupported-write'), $this->write($card, 'comment'));
        self::assertSame([], $this->liveKinds($card));
    }

    private function project(
        bool $mergePullRequests = false,
        bool $changeBase = false,
        bool $syncBehind = false,
        bool $epicDraftSwitch = false,
        bool $closeEpicPullRequests = false,
    ): Project {
        $project = $this->workflowProject('forge-write');
        $this->em()->persist(new BoardAutomationSettings(
            $project,
            syncBehind: $syncBehind,
            mergePullRequests: $mergePullRequests,
            changeBase: $changeBase,
            epicDraftSwitch: $epicDraftSwitch,
            closeEpicPullRequests: $closeEpicPullRequests,
        ));
        $this->em()->flush();

        return $project;
    }

    private function write(Card $card, string $write, ?string $fallback = 'fallback', bool $writers = true): ActionOutcome
    {
        $registered = $writers ? [$this->writer] : [];
        $forgePullRequests = $this->service(ForgePullRequestRepository::class);
        $action = new ForgeWrite(
            new CardPullRequests($this->service(CardPullRequestRepository::class), $forgePullRequests),
            $this->service(BoardAutomation::class),
            new ForgePullRequestWrites(
                new PullRequestMergers($registered),
                new PullRequestBaseChangers($registered),
                $forgePullRequests,
                $this->service(EntityManagerInterface::class),
                new MockClock('2026-10-02 12:00:00'),
            ),
            $forgePullRequests,
            new PullRequestBranchUpdaters($registered),
            new PullRequestStateWriters($registered),
            new WorkRequestOpener($this->openWorkRequestHandler()),
            'squash',
        );

        $params = null === $fallback ? ['write' => $write] : ['write' => $write, 'fallback' => $fallback];

        return $action->run($this->rule(ActionType::ForgeWrite, $params), $card, FactsMother::facts(), $this->state($card));
    }

    /** @return list<string> */
    private function liveKinds(Card $card): array
    {
        $kinds = array_map(
            static fn ($request): string => $request->kind,
            $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')),
        );
        sort($kinds);

        return $kinds;
    }
}
