<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service\Dev;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes a project's worth of work for a development instance: cards,
 * documents, open and completed requests, replies and site feedback.
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
        private InboxSearchIndexer $inboxSearch,
        private DocumentSearchIndexer $documentSearch,
    ) {
    }

    /** False when the project already holds the showcase, so a second run writes nothing. */
    public function __invoke(Project $project, User $owner, User $reviewer): bool
    {
        if ($this->inboxItems->findOneBy(['project' => $project, 'title' => self::MARKER_TITLE]) instanceof InboxItem) {
            return false;
        }

        $cards = $this->seedCards($project);
        $documents = $this->seedDocuments($owner, $project);
        $this->seedInbox($project, $owner, $reviewer, $cards, $documents);
        $this->seedSiteFeedback($project, $cards['checkout']);
        $this->em->flush();

        return true;
    }

    /** @return array{checkout: Card, history: Card, columns: Card, onboarding: Card, pullRequest: CardPullRequest} */
    private function seedCards(Project $project): array
    {
        $columns = [];
        foreach ($this->boardColumns->findForProject($project) as $column) {
            $columns[$column->slug] = $column;
        }
        $backlog = $columns['backlog'] ?? throw new \LogicException('The project has no backlog column.');
        $number = $this->cards->nextNumber($project);

        $checkout = new Card(
            project: $project,
            column: $columns['in-progress'] ?? $backlog,
            title: 'A simpler checkout',
            body: 'Replace the legacy checkout with a single-page flow. Keep saved subscriptions and recovery behavior intact.',
            number: $number,
            type: CardType::Feature,
        );
        $history = new Card(
            project: $project,
            column: $columns['in-progress'] ?? $backlog,
            title: 'Worker run history',
            body: 'Give every reported run a place in the project: its outcome, its duration, the rule that started it and the card it belonged to.',
            number: $number + 1,
            type: CardType::Feature,
        );
        $columnRules = new Card(
            project: $project,
            column: $columns['next'] ?? $backlog,
            title: 'Safer column changes',
            body: 'A column rename must not silently detach the rules that point at it. Decide the handoff behavior before implementation.',
            number: $number + 2,
            type: CardType::Bug,
        );
        $onboarding = new Card(
            project: $project,
            column: $backlog,
            title: 'Board onboarding',
            body: 'Make the first agent handoff obvious. Explain which rule runs when a card enters Ready and what the person should expect back.',
            number: $number + 3,
            type: CardType::Feature,
        );

        foreach ([$checkout, $history, $columnRules, $onboarding] as $card) {
            $this->em->persist($card);
        }
        $links = $this->pullRequests->linksFor($checkout, ['https://github.com/example/atlas/pull/438']);
        $pullRequest = $links[0] ?? throw new \LogicException('The pull request resolver returned no link.');
        foreach ($links as $link) {
            $this->em->persist($link);
        }
        $this->em->flush();

        return ['checkout' => $checkout, 'history' => $history, 'columns' => $columnRules, 'onboarding' => $onboarding, 'pullRequest' => $pullRequest];
    }

    /** @return array{history: Document, rules: Document} */
    private function seedDocuments(User $owner, Project $project): array
    {
        $history = new Document($owner, $project, 'Worker run history');
        $history->addVersion(
            "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.\n\n## Proposed approach\n\nGive every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.\n",
            '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session. A person needs to see what ran, what happened, and which card it belonged to.</p><h2>Proposed approach</h2><p>Give every reported run a place in the project. Show its outcome, duration, matched rule, and the card that started it.</p>',
        );
        $history->addVersion(
            "# Worker run history\n\n## The problem\n\nWhen an agent finishes, its output disappears into a terminal session.\n\n## Failure and recovery\n\nA failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt.\n",
            '<h1>Worker run history</h1><h2>The problem</h2><p>When an agent finishes, its output disappears into a terminal session.</p><h2>Failure and recovery</h2><p>A failed run shows the original output and the reason it stopped. A retry starts a new run and preserves the earlier attempt.</p>',
            'Answered the failure question.',
        );

        $rules = new Document($owner, $project, 'Column rules');
        $rules->addVersion(
            "# Column rules\n\n## Renaming a column\n\nA rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.\n",
            '<h1>Column rules</h1><h2>Renaming a column</h2><p>A rule points at a column by its stable id, so a rename keeps the rule attached. The board shows the new label everywhere the old one appeared.</p>',
        );
        $rules->status = DocumentStatus::Approved;

        foreach ([$history, $rules] as $document) {
            $this->em->persist($document);
        }
        $this->em->flush();
        foreach ([$history, $rules] as $document) {
            $this->documentSearch->index($document);
        }

        return ['history' => $history, 'rules' => $rules];
    }

    /**
     * @param array{checkout: Card, history: Card, columns: Card, onboarding: Card, pullRequest: CardPullRequest} $cards
     * @param array{history: Document, rules: Document}                                                           $documents
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
     * @param array{history: Document, rules: Document} $documents
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
            type: CardType::SiteReview,
            origin: CardReporter::Reviewer,
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
