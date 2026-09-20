<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewCommand;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\InboxReviewLookup;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;

final class InboxReviewLookupTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_a_verdict_submitted_after_the_lookup_read_the_review_shows_through(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'review-lookup');
        $project = $this->project($em, $owner, 'review-lookup');
        $target = new CardPullRequest($this->card($em, $project), 'https://github.com/example/project/pull/5');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review this PR', false);
        foreach ([$target, $item, new InboxReview($item, $target)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $item = $em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $item);
        $owner = $item->project->owner;
        $lookup = self::getContainer()->get(InboxReviewLookup::class);
        self::assertInstanceOf(InboxReviewLookup::class, $lookup);
        $lookup->preload([$item]);
        self::assertNull($lookup->forItem($item)?->verdict);

        $submit = new SubmitInboxPullRequestReviewHandler(
            self::getContainer()->get(InboxItemCloser::class),
            self::getContainer()->get(InboxReviewRepository::class),
            self::getContainer()->get(CardPullRequestRepository::class),
            self::getContainer()->get(Auditor::class),
        );
        $submit(new SubmitInboxPullRequestReviewCommand($item, $owner, 'approved', 'https://github.com/example/project/pull/5'));

        self::assertSame(InboxReviewVerdict::Approved, $lookup->forItem($item)?->verdict);
    }
}
