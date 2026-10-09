<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\ForgePullRequestWrites;
use App\Module\Forge\Service\PullRequestBaseChangers;
use App\Module\Forge\Service\PullRequestBranchUpdaters;
use App\Module\Forge\Service\PullRequestMergers;
use App\Module\Forge\Service\PullRequestOpeners;
use App\Module\Forge\Service\PullRequestStateWriters;
use App\Module\Forge\Service\PullRequestSyncFailed;
use App\Module\Forge\Service\PullRequestWriteFailed;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Service\CardPullRequests;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Support\ShippedCardTypes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

        self::assertOpenedWork($this->write($card, 'merge', fallback: 'merge'));

        self::assertSame([], $this->writer->calls);
        self::assertSame(['merge'], $this->liveKinds($card));
    }

    public function test_the_fallback_work_carries_the_pull_request_and_the_reason_as_its_context(): void
    {
        $card = $this->card($this->project(syncBehind: true), 'in-review');
        $pullRequest = $this->pullRequest($card, headSha: 'abc1234');
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(conflicting: true));

        self::assertOpenedWork($this->write($card, 'update-branch', fallback: 'sync', writers: false, facts: $facts));

        $live = $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
        self::assertCount(1, $live);
        self::assertEquals(
            new WorkRequestContext($pullRequest->number, 'https://github.com/acme/widgets/pull/'.$pullRequest->number, 'abc1234', 'conflict'),
            $live[0]->context,
        );
    }

    public function test_it_merges_the_primary_pull_request_with_the_configured_method(): void
    {
        $card = $this->card($this->project(mergePullRequests: true), 'in-review');
        $pullRequest = $this->pullRequest($card, headSha: 'abc123');

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'merge'));

        self::assertSame([['merge', $pullRequest->number, 'squash', 'abc123']], $this->writer->calls);
        self::assertSame([], $this->liveKinds($card));
    }

    public function test_it_merges_the_pull_request_the_facts_read_and_not_the_newest_one(): void
    {
        $card = $this->card($this->project(mergePullRequests: true), 'in-review');
        $base = $this->pullRequest($card, head: 'base-branch', headSha: 'aaa111');
        $base->openedAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $upper = $this->pullRequest($card, base: 'base-branch', headSha: 'bbb222');
        $upper->openedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->em()->flush();

        self::assertEquals(ActionOutcome::done(), $this->write($card, 'merge', facts: FactsMother::facts(pullRequest: FactsMother::pullRequest(id: $base->id))));

        self::assertSame([['merge', $base->number, 'squash', 'aaa111']], $this->writer->calls);
    }

    public function test_a_forge_with_no_merger_opens_the_fallback_work(): void
    {
        $card = $this->card($this->project(mergePullRequests: true, changeBase: true), 'in-review');
        $this->pullRequest($card, base: 'parent');
        $this->pullRequest($this->card($card->project, 'done'), state: PullRequestState::Merged, base: 'main', head: 'parent');

        self::assertOpenedWork($this->write($card, 'merge', fallback: 'merge', writers: false));
        self::assertOpenedWork($this->write($card, 'change-base', fallback: 'rebase-stacked', writers: false));

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

        self::assertOpenedWork($this->write($card, 'update-branch', fallback: 'sync', writers: false));
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

        self::assertOpenedWork($this->write($card, 'draft', fallback: 'draft-switch', writers: false));
        self::assertOpenedWork($this->write($card, 'close', fallback: 'close-epic'));

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

    public function test_an_epic_opening_that_is_off_refuses_so_a_retry_opens_it_later(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project());

        self::assertEquals(ActionOutcome::refused('open-epic-off'), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->writer->calls);
        self::assertSame([], $this->liveKinds($epic));
    }

    public function test_an_epic_opening_on_a_card_that_is_not_an_epic_does_nothing(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $epic->type = 'feature';

        self::assertEquals(ActionOutcome::done(), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->writer->calls);
    }

    public function test_it_opens_the_epic_pull_request_and_links_it_to_the_epic(): void
    {
        [$epic, $childPullRequest] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $existing = $this->pullRequest($epic, state: PullRequestState::Closed, head: 'epic/'.$epic->number);
        foreach ($this->service(CardPullRequestRepository::class)->findBy(['card' => $epic]) as $link) {
            $epic->pullRequests->add($link);
        }

        self::assertEquals(ActionOutcome::done(), $this->write($epic, 'open-epic', fallback: null));

        self::assertCount(1, $this->writer->calls);
        self::assertSame(['open', $childPullRequest->number, 'epic/'.$epic->number, 'main', 'Epic'], \array_slice($this->writer->calls[0], 0, 5));
        $body = $this->writer->calls[0][5];
        self::assertIsString($body);
        self::assertStringContainsString('`epic/'.$epic->number.'`', $body);
        self::assertMatchesRegularExpression('#https?://\S+/projects/'.$epic->project->id.'/board/cards/'.$epic->id.'#', $body);
        self::assertSame([
            ['forge' => 'github', 'repository' => 'acme/widgets', 'number' => $existing->number],
            ['forge' => 'github', 'repository' => 'acme/widgets', 'number' => 900],
        ], $this->service(CardPullRequestRepository::class)->findCurrentKeys($epic));
    }

    public function test_an_epic_that_links_its_open_pull_request_opens_nothing(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $this->pullRequest($epic, head: 'epic/'.$epic->number);

        self::assertEquals(ActionOutcome::done(), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->writer->calls);
    }

    public function test_an_epic_that_links_the_opened_pull_request_in_another_case_gets_no_second_link(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $this->writer->openedNumber = 77;
        $this->em()->persist(new CardPullRequest($epic, 'https://github.com/Acme/Widgets/pull/77', Forge::GitHub, 'Acme/Widgets', 77));
        $this->em()->flush();

        self::assertEquals(ActionOutcome::done(), $this->write($epic, 'open-epic', fallback: null));
        self::assertCount(1, $this->writer->calls);
        self::assertCount(1, $this->service(CardPullRequestRepository::class)->findCurrentKeys($epic));
    }

    public function test_an_epic_with_no_merged_child_is_refused(): void
    {
        $project = $this->project(openEpicPullRequests: true);
        $epic = $this->epic($project);
        $child = $this->card($project, 'in-review');
        $child->parent = $epic;
        $this->pullRequest($child, base: 'epic/'.$epic->number);

        self::assertEquals(ActionOutcome::refused('no-merged-child'), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->writer->calls);
    }

    public function test_a_merged_child_with_no_default_branch_is_refused(): void
    {
        [$epic, $childPullRequest] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $childPullRequest->defaultBranch = null;
        $this->em()->flush();

        self::assertEquals(ActionOutcome::refused('no-default-branch'), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->writer->calls);
    }

    public function test_a_failed_epic_opening_is_refused_with_its_cause_and_links_nothing(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));
        $this->writer->failure = new PullRequestWriteFailed('api_failed_http_status_422', permanent: false);

        self::assertEquals(ActionOutcome::refused('api-failed-http-status-422'), $this->write($epic, 'open-epic', fallback: null));
        self::assertSame([], $this->service(CardPullRequestRepository::class)->findCurrentKeys($epic));
    }

    public function test_an_epic_opening_with_no_opener_for_the_forge_does_nothing(): void
    {
        [$epic] = $this->epicWithMergedChild($this->project(openEpicPullRequests: true));

        self::assertEquals(ActionOutcome::done(), $this->write($epic, 'open-epic', fallback: null, writers: false));
        self::assertSame([], $this->service(CardPullRequestRepository::class)->findCurrentKeys($epic));
    }

    private function epic(Project $project): Card
    {
        $epic = $this->card($project, 'in-review');
        $epic->type = 'epic';
        $epic->title = 'Epic';
        $this->em()->flush();

        return $epic;
    }

    /** @return array{Card, ForgePullRequest} the epic, and the merged pull request of its child */
    private function epicWithMergedChild(Project $project): array
    {
        $epic = $this->epic($project);
        $child = $this->card($project, 'done');
        $child->parent = $epic;
        $this->em()->flush();

        return [$epic, $this->pullRequest($child, state: PullRequestState::Merged, base: 'epic/'.$epic->number)];
    }

    private function project(
        bool $mergePullRequests = false,
        bool $changeBase = false,
        bool $syncBehind = false,
        bool $epicDraftSwitch = false,
        bool $closeEpicPullRequests = false,
        bool $openEpicPullRequests = false,
    ): Project {
        $project = $this->workflowProject('forge-write');
        $this->em()->persist(new BoardAutomationSettings(
            $project,
            syncBehind: $syncBehind,
            mergePullRequests: $mergePullRequests,
            changeBase: $changeBase,
            epicDraftSwitch: $epicDraftSwitch,
            closeEpicPullRequests: $closeEpicPullRequests,
            openEpicPullRequests: $openEpicPullRequests,
        ));
        $this->em()->flush();

        return $project;
    }

    private function write(Card $card, string $write, ?string $fallback = 'fallback', bool $writers = true, ?Facts $facts = null): ActionOutcome
    {
        $registered = $writers ? [$this->writer] : [];
        $forgePullRequests = $this->service(ForgePullRequestRepository::class);
        $action = new ForgeWrite(
            $this->service(CardRepository::class),
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
            new PullRequestOpeners($registered),
            $this->opener(),
            $this->service(UpdateCardHandler::class),
            $this->service(UrlGeneratorInterface::class),
            new ShippedCardTypes(),
            'squash',
        );

        $params = null === $fallback ? ['write' => $write] : ['write' => $write, 'fallback' => $fallback];

        return $this->runAction($action, $this->rule('forge-write', $params), $card->snapshot(), $facts ?? FactsMother::facts(), $this->state($card));
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
