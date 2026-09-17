<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewCommand;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;

final class SubmitInboxPullRequestReviewTest extends KernelTestCase
{
    use InboxFixtures;

    #[TestWith(['approved'])]
    #[TestWith(['changes-requested'])]
    public function test_the_result_is_stored_once_without_changing_the_pull_request(string $verdict): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'pr-review');
        $project = $this->project($em, $owner, 'pr-review');
        $target = new CardPullRequest($this->card($em, $project), 'https://github.com/example/project/pull/12');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review this PR', false);
        $review = new InboxReview($item, $target);
        foreach ([$target, $item, $review] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $handler = $this->handler();
        $command = new SubmitInboxPullRequestReviewCommand($item, $owner, $verdict, $target->url, '  Explain the retry limit.  ');
        $handler($command);
        try {
            $handler($command);
            self::fail('A completed review cannot be submitted twice.');
        } catch (DomainErrors $error) {
            self::assertSame(['verdict' => 'inbox.item.error.final'], $error->errors);
        }
        $decline = self::getContainer()->get(DeclineInboxItemHandler::class);
        self::assertInstanceOf(DeclineInboxItemHandler::class, $decline);
        try {
            $decline(new DeclineInboxItemCommand($item, 'Replace the result.'));
            self::fail('Declining cannot replace a completed review.');
        } catch (DomainErrors $error) {
            self::assertSame(['closeNote' => 'inbox.item.error.final'], $error->errors);
        }
        $em->clear();
        $saved = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $saved);
        self::assertSame(InboxReviewVerdict::from($verdict), $saved->verdict);
        self::assertSame('Explain the retry limit.', $saved->note);
        self::assertNotNull($saved->submittedAt);
        self::assertSame((string) $owner->id, (string) $saved->reviewer?->id);
        self::assertSame(InboxItemState::Done, $saved->item->state);
        self::assertNull($saved->documentReview);
        self::assertNull($saved->reviewedVersionNumber);
        self::assertSame($target->url, $saved->pullRequest?->url);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_changed_or_removed_target_leaves_the_request_open(bool $removed): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'pr-stale');
        $project = $this->project($em, $owner, 'pr-stale');
        $target = new CardPullRequest($this->card($em, $project), 'https://github.com/example/project/pull/12');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review this PR', false);
        $review = new InboxReview($item, $target);
        foreach ([$target, $item, $review] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        if ($removed) {
            $em->getConnection()->executeStatement('DELETE FROM board_card_pull_requests WHERE id = ?', [(string) $target->id]);
        } else {
            $em->getConnection()->executeStatement('UPDATE board_card_pull_requests SET url = ? WHERE id = ?', ['https://github.com/example/project/pull/13', (string) $target->id]);
        }
        $handler = $this->handler();
        try {
            $handler(new SubmitInboxPullRequestReviewCommand($item, $owner, 'approved', $target->url));
            self::fail('The form must refer to the current URL.');
        } catch (DomainErrors $error) {
            self::assertSame(['expectedUrl' => $removed ? 'inbox.review.error.target_unavailable' : 'inbox.review.error.target_changed'], $error->errors);
        }
        self::assertTrue($em->isOpen());
        $em->clear();
        self::assertSame(InboxItemState::Open, $em->find(InboxItem::class, $item->id)?->state);
        self::assertNull($em->find(InboxReview::class, $review->id)?->verdict);
    }

    #[TestWith(['changes-requested', '   ', 'note', 'review.document.flash.note_required'])]
    #[TestWith(['withdrawn', 'A note', 'verdict', 'review.document.flash.verdict_invalid'])]
    public function test_invalid_feedback_does_not_complete_the_request(string $verdict, string $note, string $field, string $errorKey): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'pr-feedback');
        $project = $this->project($em, $owner, 'pr-feedback');
        $target = new CardPullRequest($this->card($em, $project), 'https://github.com/example/project/pull/12');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review this PR', false);
        $review = new InboxReview($item, $target);
        foreach ([$target, $item, $review] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        try {
            ($this->handler())(new SubmitInboxPullRequestReviewCommand($item, $owner, $verdict, $target->url, $note));
            self::fail('Invalid feedback must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame([$field => $errorKey], $error->errors);
        }
        $em->clear();
        self::assertSame(InboxItemState::Open, $em->find(InboxItem::class, $item->id)?->state);
        self::assertNull($em->find(InboxReview::class, $review->id)?->verdict);
    }

    private function handler(): SubmitInboxPullRequestReviewHandler
    {
        return new SubmitInboxPullRequestReviewHandler(
            self::getContainer()->get(InboxItemCloser::class),
            self::getContainer()->get(InboxReviewRepository::class),
            self::getContainer()->get(CardPullRequestRepository::class),
            self::getContainer()->get(Auditor::class),
        );
    }
}
