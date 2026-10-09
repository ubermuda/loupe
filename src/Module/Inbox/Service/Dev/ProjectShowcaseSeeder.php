<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a project's worth of work for a development instance: cards, a run
 * that gave up, documents, open and completed requests, replies and site
 * feedback.
 *
 * It lives in Inbox because the requests are the point, and because Inbox is
 * the one module allowed to name a card and a document, which is what a
 * request links to. `phparkitect.php` carries that exemption and its reason.
 */
#[When('dev')]
final readonly class ProjectShowcaseSeeder
{
    /** The title that says this project already holds the showcase. */
    public const string MARKER_TITLE = 'How should checkout retries work?';

    public function __construct(
        private EntityManagerInterface $em,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private InboxItemRepository $inboxItems,
        private SiteReviewCommentRepository $siteReviewComments,
        private PullRequestUrlResolver $pullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private InboxSearchIndexer $inboxSearch,
        private DocumentSearchIndexer $documentSearch,
        private CardWaitReconciler $cardWaits,
        private InboxCardWatchRepository $inboxCardWatches,
        private InboxAvailability $inbox,
        private DocumentTagApplier $tagApplier,
        private CardEventRepository $cardEvents,
        private BoardAutomationSettingsRepository $boardAutomationSettings,
    ) {
    }

    /** The card in Tech design whose tech design in review gives the showcase its wait item. */
    public const string WAITING_CARD_TITLE = 'Board onboarding';

    /** The title that says this project already holds the sync line cards. */
    public const string SYNC_MARKER_TITLE = 'Faster search indexing';

    /** The title of the card whose approval covers an older head. */
    public const string OUTDATED_APPROVAL_TITLE = 'Retry a declined payment';

    /**
     * A second run writes nothing but asks Loupe for the wait items again, so
     * a run after inbox.enabled goes on opens them.
     */
    public function __invoke(Project $project, User $owner, User $reviewer): ShowcaseSeeding
    {
        $written = !$this->inboxItems->findOneBy(['project' => $project, 'title' => self::MARKER_TITLE]) instanceof InboxItem;
        if ($written) {
            $cards = $this->seedCards($project);
            $this->seedHistory($project, $owner, $cards['history']);
            $documents = $this->seedDocuments($owner, $project, $cards['onboarding']);
            $this->seedInbox($project, $owner, $reviewer, $cards, $documents);
            $this->seedEpicLane($project);
            $this->seedSiteFeedback($project, $cards['checkout']);
            $this->em->flush();
            $waitingCard = $cards['onboarding'];
        } else {
            $waitingCard = $this->cards->findOneBy(['project' => $project, 'title' => self::WAITING_CARD_TITLE]);
        }

        // Its own marker lets a project that already holds the showcase gain these cards.
        $syncCards = [];
        if (!$this->cards->findOneBy(['project' => $project, 'title' => self::SYNC_MARKER_TITLE]) instanceof Card) {
            $cards = $this->seedSyncLine($project);
            $this->em->flush();
            $syncCards = array_map(static fn (Card $card): string => '/projects/'.$project->id.'/board/cards/'.$card->id, $cards);
        }
        $outdatedCard = null;
        if (!$this->cards->findOneBy(['project' => $project, 'title' => self::OUTDATED_APPROVAL_TITLE]) instanceof Card) {
            $card = $this->seedOutdatedApproval($project);
            $this->em->flush();
            $outdatedCard = '/projects/'.$project->id.'/board/cards/'.$card->id;
        }

        $enabled = $this->inbox->isEnabled();
        if (!$waitingCard instanceof Card) {
            return new ShowcaseSeeding($written, false, $enabled, 0, $syncCards, $outdatedCard);
        }
        $cardId = $waitingCard->id ?? throw new \LogicException('A stored card has an id.');
        $this->cardWaits->reconcile($project, null);

        return new ShowcaseSeeding(
            $written,
            [] !== $this->inboxCardWatches->findOpenForCards($project, [$cardId]),
            $enabled,
            \count($this->inboxCardWatches->findOpenCardIds($project)),
            $syncCards,
            $outdatedCard,
        );
    }

    /**
     * Turns the automation and the sync of a behind pull request on, and links one pull request
     * per sync status. The holder keeps the line busy, so no sync pass picks
     * a pull request of this showcase.
     *
     * @return list<Card>
     */
    private function seedSyncLine(Project $project): array
    {
        $settings = $this->boardAutomationSettings->findOneByProject($project) ?? new BoardAutomationSettings($project);
        // The card page shows no sync status while the automation is off.
        $settings->enabled = true;
        $settings->syncBehind = true;
        $this->em->persist($settings);

        $column = $this->reviewColumn($project);
        $number = $this->cards->nextNumber($project);
        $cards = [];
        foreach ([self::SYNC_MARKER_TITLE, 'Retry a failed webhook', 'Paginate the activity feed', 'Cache the board columns', 'Rename the export archive'] as $offset => $title) {
            $cards[] = $card = new Card(project: $project, column: $column, title: $title, body: '', number: $number + $offset, type: 'feature');
            $this->em->persist($card);
        }

        $this->syncRow($cards[0], 452, PullRequestMergeability::Behind, null);
        $this->syncRow($cards[1], 453, PullRequestMergeability::Conflicting, '-3 hours');
        $this->syncRow($cards[2], 454, PullRequestMergeability::Behind, '-2 hours');
        $holder = $this->syncRow($cards[3], 455, PullRequestMergeability::Mergeable, '-4 hours');
        $holder->syncedSha = $holder->headSha;
        $this->syncRow($cards[4], 456, PullRequestMergeability::Behind, '-1 hour')->syncFailedReason = 'permission';

        return $cards;
    }

    /** A pull request that passes every check, with an approval of a head before the last push. */
    private function seedOutdatedApproval(Project $project): Card
    {
        $card = new Card(project: $project, column: $this->reviewColumn($project), title: self::OUTDATED_APPROVAL_TITLE, body: '', number: $this->cards->nextNumber($project), type: 'feature');
        $this->em->persist($card);

        $state = $this->syncRow($card, 457, PullRequestMergeability::Mergeable, '-3 hours');
        $state->checks = PullRequestChecks::Passed;
        $state->approvalSha = $state->coveredSha = hash('sha1', 'atlas-457-old');
        $state->uncoveredSha = $state->headSha;
        $state->readyToMerge = false;

        return $card;
    }

    private function reviewColumn(Project $project): BoardColumn
    {
        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }

        return $columns['in-review'] ?? $columns['in-progress'] ?? $columns['backlog'] ?? throw new \LogicException('The project has no backlog column.');
    }

    /** A pull request whose base is the default branch, approved on its head when $approvedAt is set. */
    private function syncRow(Card $card, int $number, PullRequestMergeability $mergeability, ?string $approvedAt): ForgePullRequest
    {
        [, $state] = $this->link($card, $number);
        $state->headSha = hash('sha1', 'atlas-'.$number);
        $state->baseBranch = 'main';
        $state->defaultBranch = 'main';
        $state->checks = PullRequestChecks::Pending;
        $state->checksSha = $state->headSha;
        $state->mergeability = $mergeability;
        $state->review = PullRequestReview::Required;
        $state->refreshedAt = new \DateTimeImmutable('-5 minutes');
        if (null !== $approvedAt) {
            $state->review = PullRequestReview::Approved;
            $state->approvalId = 'atlas-review-'.$number;
            $state->approvalSha = $state->coveredSha = $state->headSha;
            $state->approvedAt = new \DateTimeImmutable($approvedAt);
        }

        return $state;
    }

    /** @return array{checkout: Card, history: Card, columns: Card, onboarding: Card, pullRequest: CardPullRequest} */
    private function seedCards(Project $project): array
    {
        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $backlog = $columns['backlog'] ?? throw new \LogicException('The project has no backlog column.');
        $columns['tech-design'] ??= $this->techDesignColumn($project, $columns);
        $number = $this->cards->nextNumber($project);

        $checkout = new Card(
            project: $project,
            column: $columns['in-progress'] ?? $backlog,
            title: 'A simpler checkout',
            body: 'Replace the legacy checkout with a single-page flow. Keep saved subscriptions and recovery behavior intact.',
            number: $number,
            type: 'feature',
            source: new CardSource(CardSourceKind::Person),
        );
        $history = new Card(
            project: $project,
            column: $columns['in-progress'] ?? $backlog,
            title: 'Worker run history',
            body: 'Give every reported run a place in the project: its outcome, its duration, the rule that started it and the card it belonged to.',
            number: $number + 1,
            type: 'feature',
        );
        $columnRules = new Card(
            project: $project,
            column: $columns['next'] ?? $backlog,
            title: 'Safer column changes',
            body: 'A column rename must not silently detach the rules that point at it. Decide the handoff behavior before implementation.',
            number: $number + 2,
            type: 'bug',
            source: CardSource::run(Uuid::v4(), Uuid::v4()),
        );
        $onboarding = new Card(
            project: $project,
            column: $columns['tech-design'],
            title: self::WAITING_CARD_TITLE,
            body: 'Make the first agent handoff obvious. Explain which rule runs when a card enters Ready and what the person should expect back.',
            number: $number + 3,
            type: 'feature',
            source: new CardSource(CardSourceKind::Loupe),
        );

        foreach ([$checkout, $history, $columnRules, $onboarding] as $card) {
            $this->em->persist($card);
        }
        $pullRequest = $this->linkPullRequest($checkout, 438, PullRequestChecks::Failed, PullRequestMergeability::Mergeable, PullRequestReview::Required, ['phpunit', 'e2e-chromium']);
        $this->linkPullRequest($history, 441, PullRequestChecks::Passed, PullRequestMergeability::Conflicting, PullRequestReview::Required);
        $this->linkPullRequest($history, 449, PullRequestChecks::Passed, PullRequestMergeability::Blocked, PullRequestReview::Required);
        $this->linkPullRequest($columnRules, 445, PullRequestChecks::Failed, PullRequestMergeability::Mergeable, PullRequestReview::ChangesRequested, ['lint']);
        $this->linkPullRequest($onboarding, 447, PullRequestChecks::Passed, PullRequestMergeability::Mergeable, PullRequestReview::Approved);

        $this->em->flush();

        return ['checkout' => $checkout, 'history' => $history, 'columns' => $columnRules, 'onboarding' => $onboarding, 'pullRequest' => $pullRequest];
    }

    /**
     * An open column after Next, so a tech design in review on a card there waits.
     *
     * @param array<string, BoardColumn> $columns
     */
    private function techDesignColumn(Project $project, array $columns): BoardColumn
    {
        $position = isset($columns['next']) ? $columns['next']->position + 1 : \count($columns);
        foreach ($columns as $column) {
            if ($column->position >= $position) {
                ++$column->position;
            }
        }
        $column = new BoardColumn(project: $project, label: 'Tech design', slug: 'tech-design', position: $position);
        $this->em->persist($column);

        return $column;
    }

    /**
     * No App installation reads example/atlas, so a refresh fails as not
     * transient and keeps this state.
     *
     * @param list<string> $failedChecks
     */
    private function linkPullRequest(Card $card, int $number, PullRequestChecks $checks, PullRequestMergeability $mergeability, PullRequestReview $review, array $failedChecks = []): CardPullRequest
    {
        [$link, $state] = $this->link($card, $number);
        $state->headSha = hash('sha1', 'atlas-'.$number);
        $state->baseBranch = 'main';
        $state->checks = $checks;
        $state->checksSha = $state->headSha;
        $state->failedChecks = $failedChecks;
        $state->mergeability = $mergeability;
        $state->review = $review;
        $state->changesRequestedSha = PullRequestReview::ChangesRequested === $review ? $state->headSha : null;
        $state->refreshedAt = new \DateTimeImmutable('-5 minutes');

        return $link;
    }

    /** @return array{CardPullRequest, ForgePullRequest} */
    private function link(Card $card, int $number): array
    {
        $links = $this->pullRequests->linksFor($card, ['https://github.com/example/atlas/pull/'.$number]);
        $link = $links[0] ?? throw new \LogicException('The pull request resolver returned no link.');
        $this->em->persist($link);

        $state = $this->forgePullRequests->findOneBy(['project' => $card->project, 'forge' => 'github', 'repository' => 'example/atlas', 'number' => $number])
            ?? new ForgePullRequest($card->project, 'github', 'example/atlas', $number);
        $this->em->persist($state);

        return [$link, $state];
    }

    /** @return array{history: Document, rules: Document, onboarding: Document} */
    private function seedDocuments(User $owner, Project $project, Card $onboardingCard): array
    {
        $history = new Document($owner, $project, 'Worker run history');
        $history->addVersion(
            "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.\n\n## Proposed approach\n\nGive every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.\n",
            '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.</p><h2>Proposed approach</h2><p>Give every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.</p>',
        );
        $history->addVersion(
            "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session.\n\n## Failure and recovery\n\nA failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt. The matched rule keeps its label after a rename, as C1 requires.\n",
            '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session.</p><h2>Failure and recovery</h2><p>A failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt. The matched rule keeps its label after a rename, as C1 requires.</p>',
            'Answered the failure question.',
        );

        $rules = new Document($owner, $project, 'Column rules');
        $rules->addVersion(
            "# Column rules\n\n## Renaming a column\n\nA rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.\n\n## Constraints\n\n1. **C1: A rename keeps every rule attached.** The rule stores the column id, not its label.\n2. **C2: A deleted column disables its rules.** The board lists them for review.\n\nC2 matters less than C1 while columns are rarely deleted.\n",
            '<h1>Column rules</h1><h2>Renaming a column</h2><p>A rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.</p><h2>Constraints</h2><ol><li><strong>C1: A rename keeps every rule attached.</strong> The rule stores the column id, not its label.</li><li><strong>C2: A deleted column disables its rules.</strong> The board lists them for review.</li></ol><p>C2 matters less than C1 while columns are rarely deleted.</p>',
        );
        $rules->status = DocumentStatus::Approved;
        $history->addReference($rules);

        $onboarding = new Document($owner, $project, 'Tech design: First handoff guide');
        $onboarding->addVersion(
            "# Tech design: First handoff guide\n\n## The first five minutes\n\nMove a card to Ready. The rule of that column starts an agent, and the card shows the run.\n",
            '<h1>Tech design: First handoff guide</h1><h2>The first five minutes</h2><p>Move a card to Ready. The rule of that column starts an agent, and the card shows the run.</p>',
        );
        $this->tagApplier->apply($onboarding, ['tech-design', 'decisions']);
        $onboardingCard->documents->add(new CardDocument($onboardingCard, $onboarding));

        foreach ([$history, $rules, $onboarding] as $document) {
            $this->em->persist($document);
        }
        $this->em->flush();
        foreach ([$history, $rules, $onboarding] as $document) {
            $this->documentSearch->index($document);
        }

        return ['history' => $history, 'rules' => $rules, 'onboarding' => $onboarding];
    }

    /**
     * @param array{checkout: Card, history: Card, columns: Card, onboarding: Card, pullRequest: CardPullRequest} $cards
     * @param array{history: Document, rules: Document, onboarding: Document}                                     $documents
     */
    private function seedInbox(Project $project, User $owner, User $reviewer, array $cards, array $documents): void
    {
        $now = new \DateTimeImmutable();
        $number = $this->inboxItems->nextNumber($project);

        $retries = new InboxItem(
            project: $project,
            number: $number,
            kind: InboxItemKind::Question,
            title: self::MARKER_TITLE,
            blocking: true,
            body: 'The new checkout can retry a failed payment automatically, or leave the next attempt to the customer. Which behavior should I implement?',
            options: ['Retry once, then let the customer decide', 'Always let the customer retry'],
            freeText: true,
            createdAt: $now->modify('-6 minutes'),
        );
        $retries->cards->add(new InboxItemCard($retries, $cards['checkout']));

        $runHistory = new InboxItem(
            project: $project,
            number: $number + 1,
            kind: InboxItemKind::Review,
            title: 'Worker run history is ready to review',
            blocking: false,
            body: 'Two sections changed. One comment needs your confirmation before I carry on.',
            createdAt: $now->modify('-12 minutes'),
        );
        $runHistory->cards->add(new InboxItemCard($runHistory, $cards['history']));
        $runHistory->documents->add(new InboxItemDocument($runHistory, $documents['history']));

        $columnRules = new InboxItem(
            project: $project,
            number: $number + 2,
            kind: InboxItemKind::Review,
            title: 'Safer column changes',
            blocking: false,
            body: 'Tester has a design specification for you. It settles what a rename does to a rule that points at the column.',
            createdAt: $now->modify('-38 minutes'),
        );
        $columnRules->cards->add(new InboxItemCard($columnRules, $cards['columns']));
        $columnRules->documents->add(new InboxItemDocument($columnRules, $documents['rules']));

        $handoff = new InboxItem(
            project: $project,
            number: $number + 3,
            kind: InboxItemKind::Todo,
            title: 'Give the first handoff a quick look',
            blocking: true,
            body: 'Read the first useful handoff document and confirm that the first five minutes feel clear. Leave a note if a step still needs work.',
            createdAt: $now->modify('-1 hour'),
        );
        $handoff->cards->add(new InboxItemCard($handoff, $cards['onboarding']));

        $pullRequest = new InboxItem(
            project: $project,
            number: $number + 4,
            kind: InboxItemKind::Review,
            title: 'Review pull request 438',
            blocking: true,
            body: 'The subscription migration is ready. Approving here records your decision; it posts no review to the code host.',
            createdAt: $now->modify('-2 hours'),
        );
        $pullRequest->cards->add(new InboxItemCard($pullRequest, $cards['checkout']));

        // One ask a bridge started and one it did not, so both bylines show.
        $this->ask($project, [$retries], $now->modify('-6 minutes'), 'Working on the **checkout** card. I need one decision before I write the recovery flow.', Uuid::v4());
        $this->ask($project, [$runHistory, $pullRequest], $now->modify('-12 minutes'), 'The plan and the pull request are both ready for you. Either order is fine.', null);
        $this->ask($project, [$columnRules], $now->modify('-38 minutes'), 'A specification rather than code. Read it when the board work settles.', Uuid::v4());

        // No ask holds this one, so it is the open item the list shows on its own.
        $this->em->persist($handoff);

        $this->em->persist(new InboxReview($runHistory, $documents['history']));
        $this->em->persist(new InboxReview($columnRules, $documents['rules']));
        $this->em->persist(new InboxReview($pullRequest, $cards['pullRequest']));

        $this->seedCompleted($project, $owner, $reviewer, $documents, $number + 5, $now);
        $this->em->flush();

        foreach ($this->inboxItems->findBy(['project' => $project]) as $item) {
            $this->inboxSearch->index($item);
        }
    }

    /**
     * The completed queue: an answer, a decline, a recorded review verdict, and
     * a to-do left open inside a closed ask, which is where the loose row and
     * its jump link come from.
     *
     * @param array{history: Document, rules: Document, onboarding: Document} $documents
     */
    private function seedCompleted(Project $project, User $owner, User $reviewer, array $documents, int $number, \DateTimeImmutable $now): void
    {
        $answered = new InboxItem(
            project: $project,
            number: $number,
            kind: InboxItemKind::Question,
            title: 'Which database should the export read from?',
            blocking: true,
            body: 'The replica lags by a few seconds. The primary is always current and costs more.',
            options: ['The read replica', 'The primary'],
            freeText: true,
            createdAt: $now->modify('-2 days'),
        );
        $answered->state = InboxItemState::Answered;
        $answered->selectedOptions = [0];
        $answered->answerText = 'A few seconds of lag is fine for an export.';
        $answered->closedAt = $now->modify('-2 days +30 minutes');

        $declined = new InboxItem(
            project: $project,
            number: $number + 1,
            kind: InboxItemKind::Todo,
            title: 'Write the release notes for 1.4',
            blocking: true,
            body: 'A short summary of the export work, for the changelog.',
            createdAt: $now->modify('-2 days'),
        );
        $declined->state = InboxItemState::Declined;
        $declined->closeNote = 'Someone else writes the notes this cycle. Ask again at 1.5.';
        $declined->closedAt = $now->modify('-2 days +45 minutes');

        $reviewed = new InboxItem(
            project: $project,
            number: $number + 2,
            kind: InboxItemKind::Review,
            title: 'Column rules specification is ready',
            blocking: true,
            body: 'It answers what a rename does to a rule that points at the column.',
            createdAt: $now->modify('-3 days'),
        );
        $reviewed->state = InboxItemState::Done;
        $reviewed->closedAt = $now->modify('-3 days +2 hours');
        $review = new InboxReview($reviewed, $documents['rules']);
        $review->verdict = InboxReviewVerdict::ChangesRequested;
        $review->note = 'Say what happens to a rule whose column is deleted rather than renamed.';
        $review->reviewer = $reviewer;
        $review->submittedAt = $now->modify('-3 days +2 hours');
        $review->reviewedVersionNumber = 1;
        $this->em->persist($review);

        $leftOpen = new InboxItem(
            project: $project,
            number: $number + 3,
            kind: InboxItemKind::Todo,
            title: 'Check the export against a real customer file',
            blocking: false,
            body: 'Nobody waits on this one, and it is still worth doing.',
            createdAt: $now->modify('-3 days'),
        );

        $this->ask($project, [$answered, $declined], $now->modify('-2 days'), 'Two things before I open the export pull request.', Uuid::v4(), $now->modify('-2 days +45 minutes'));
        $this->ask($project, [$reviewed, $leftOpen], $now->modify('-3 days'), 'The specification is ready to read.', Uuid::v4(), $now->modify('-3 days +2 hours'));
    }

    /** @param list<InboxItem> $items */
    private function ask(Project $project, array $items, \DateTimeImmutable $createdAt, string $context, ?Uuid $bridgeId, ?\DateTimeImmutable $closedAt = null): InboxAsk
    {
        $ask = new InboxAsk(
            project: $project,
            sessionId: Uuid::v4(),
            bridgeId: $bridgeId,
            context: $context,
            createdAt: $createdAt,
        );
        $ask->closedAt = $closedAt;
        foreach ($items as $item) {
            $this->em->persist($item);
            $ask->items->add(new InboxAskItem($ask, $item));
        }
        $this->em->persist($ask);

        return $ask;
    }

    /** A History tab with one row of each kind, and a run still open on the same card. The other cards keep no rows. */
    private function seedHistory(Project $project, User $owner, Card $card): void
    {
        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = CardEvent::columnDetail($column);
        }
        $backlog = $columns['backlog'] ?? throw new \LogicException('The project has no backlog column.');
        $inProgress = $columns['in-progress'] ?? $backlog;
        $done = $columns['done'] ?? $backlog;
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        $endedAt = new \DateTimeImmutable('-47 hours');
        $finished = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $card->number,
            workKind: 'fix',
            state: WorkerRunState::Succeeded,
            runKey: Uuid::v4(),
            startedAt: $endedAt->modify('-434 seconds'),
            endedAt: $endedAt,
            exitCode: 0,
            hasResult: true,
            output: 'The failing test now passes. The run pushed one commit.',
            receivedAt: $endedAt,
        );
        $startedAt = new \DateTimeImmutable('-4 minutes');
        $open = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::Running,
            runKey: Uuid::v4(),
            startedAt: $startedAt,
            receivedAt: $startedAt,
        );
        $this->em->persist($finished);
        $this->em->persist($open);
        $this->em->persist(new WorkerRunStateChange($finished, WorkerRunState::Succeeded, $endedAt, $endedAt));
        $this->em->persist(new WorkerRunStateChange($open, WorkerRunState::Running, $startedAt, $startedAt));
        $this->em->flush();

        $this->cardEvents->record($card, CardEventKind::Created, Actor::Human, $owner, ['column' => $backlog, 'type' => $card->type], new \DateTimeImmutable('-4 days'));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::Agent, $owner, ['from' => $backlog, 'to' => $inProgress, 'cause' => null], new \DateTimeImmutable('-3 days'));
        $this->cardEvents->record($card, CardEventKind::FixRequested, Actor::System, null, ['reason' => 'checks-failed', 'pullRequest' => 441], new \DateTimeImmutable('-48 hours'));
        $this->cardEvents->record($card, CardEventKind::RunFinished, Actor::Agent, $owner, [
            'runId' => (string) $finished->id,
            'workKind' => $finished->workKind,
            'state' => $finished->state->value,
            'interactive' => false,
            'startedAt' => $finished->startedAt?->format(\DateTimeInterface::ATOM),
            'endedAt' => $endedAt->format(\DateTimeInterface::ATOM),
            'durationSeconds' => 434,
        ], $endedAt);
        $this->cardEvents->record($card, CardEventKind::ReadyToMerge, Actor::System, null, ['pullRequest' => 441], new \DateTimeImmutable('-30 hours'));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::System, null, ['from' => $inProgress, 'to' => $done, 'cause' => CardEventCause::merged(441)->detail()], new \DateTimeImmutable('-28 hours'));
        $this->cardEvents->record($card, CardEventKind::Moved, Actor::Human, $owner, ['from' => $done, 'to' => $inProgress, 'cause' => null], new \DateTimeImmutable('-6 hours'));
    }

    /** An epic lane whose child card holds the warning of a run that gave up. */
    private function seedEpicLane(Project $project): void
    {
        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $next = $columns['next'] ?? throw new \LogicException('The project has no next column.');
        $inProgress = $columns['in-progress'] ?? $next;
        $number = $this->cards->nextNumber($project);

        $epic = new Card(
            project: $project,
            column: $next,
            title: 'Project export',
            body: 'Let an owner download every card, document and request of a project as one archive.',
            number: $number,
            type: 'epic',
        );
        $child = new Card(
            project: $project,
            column: $inProgress,
            title: 'Export the documents',
            body: 'Write each document version as Markdown, in a folder per document.',
            number: $number + 1,
            type: 'feature',
        );
        $child->parent = $epic;
        $this->em->persist($epic);
        $this->em->persist($child);
        $this->em->flush();

        $endedAt = new \DateTimeImmutable('-15 minutes');
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v4(),
            subjectType: WorkSubject::CARD,
            subjectId: $child->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $child->number,
            workKind: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: Uuid::v4(),
            endedAt: $endedAt,
            exitCode: 1,
            hasResult: true,
            output: 'The export tests still fail after three attempts. The archive writer cannot read a document that has no version.',
            receivedAt: $endedAt,
        );
        $this->em->persist($run);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::GaveUp, $endedAt, $endedAt));
    }

    private function seedSiteFeedback(Project $project, Card $checkout): void
    {
        $position = $this->siteReviewComments->nextPositionForProject($project);
        $now = new \DateTimeImmutable();

        $contrast = new SiteReviewComment(
            project: $project,
            position: $position,
            body: 'The payment button needs stronger contrast against the summary.',
            url: 'https://atlas.example/checkout',
            createdAt: $now->modify('-28 minutes'),
        );
        $contrast->anchors->add(new SiteReviewCommentAnchor($contrast, 1, 'button[data-action="pay"]', 'Complete your order'));

        $spacing = new SiteReviewComment(
            project: $project,
            position: $position + 1,
            body: 'Add more space between the items and the total in the order summary.',
            url: 'https://atlas.example/checkout',
            createdAt: $now->modify('-2 hours'),
        );
        $spacing->anchors->add(new SiteReviewCommentAnchor($spacing, 1, '.order-summary .items', 'Everyday notebook · $24'));
        $spacing->anchors->add(new SiteReviewCommentAnchor($spacing, 2, '.order-summary .total', 'Total · $28'));
        $spacing->status = SiteReviewCommentStatus::Addressed;

        $recovery = new SiteReviewComment(
            project: $project,
            position: $position + 2,
            body: 'Explain that we keep the order when a payment fails.',
            url: 'https://atlas.example/checkout',
            createdAt: $now->modify('-4 hours'),
        );
        $recovery->anchors->add(new SiteReviewCommentAnchor($recovery, 1, '.payment-recovery', 'Your order is saved while we confirm your payment.'));

        $wording = new SiteReviewComment(
            project: $project,
            position: $position + 3,
            body: 'The empty basket reads as an error. Say what to do next instead.',
            url: 'https://atlas.example/basket',
            createdAt: $now->modify('-2 days'),
        );
        $wording->status = SiteReviewCommentStatus::Resolved;

        // Every comment has a card, as the widget saves it. The resolved one
        // sits on a finished site-review card, because finishing resolves it.
        $done = array_find(
            $this->boardColumns->findForProject($project),
            static fn (BoardColumn $column): bool => $column->terminal,
        ) ?? throw new \LogicException('The project has no terminal column.');
        $basket = new Card(
            project: $project,
            column: $done,
            title: 'The empty basket reads as an error',
            body: '',
            number: $this->cards->nextNumber($project),
            type: 'feature',
            origin: Actor::Reviewer,
        );
        $basket->completedAt = $now->modify('-1 day');
        $this->em->persist($basket);

        foreach ([$contrast, $spacing, $recovery, $wording] as $comment) {
            $this->em->persist($comment);
        }
        foreach ([$contrast, $spacing, $recovery] as $comment) {
            $this->em->persist(new CardSiteReviewComment($checkout, $comment));
        }
        $this->em->persist(new CardSiteReviewComment($basket, $wording, true, $basket->title));
    }
}
