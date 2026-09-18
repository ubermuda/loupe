<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Service\InboxReviewAccountPurger;
use App\Module\Inbox\Service\InboxReviewExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxReviewLifecycleTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_export_includes_the_result_and_excludes_another_account(): void
    {
        $review = $this->seedReview('export-review');
        $other = $this->seedReview('export-other');
        $this->em->flush();
        $owner = $review->item->project->owner;
        $expected = [
            'id' => (string) $review->id,
            'itemId' => (string) $review->item->id,
            'targetKind' => 'document',
            'targetLabel' => 'The design',
            'documentId' => (string) $review->document?->id,
            'pullRequestId' => null,
            'verdict' => 'approved',
            'note' => 'The retry behaviour is clear.',
            'submittedAt' => '2026-09-17T00:00:00+00:00',
            'reviewerId' => (string) $owner->id,
            'reviewedVersionNumber' => 1,
            'documentReviewId' => (string) $review->documentReview?->id,
        ];
        self::assertNotNull($other->id);
        $this->em->clear();
        $exporter = self::getContainer()->get(InboxReviewExporter::class);
        self::assertInstanceOf(InboxReviewExporter::class, $exporter);
        self::assertSame('inbox_reviews.json', $exporter->filename());
        self::assertSame([$expected], iterator_to_array($exporter->export($owner), false));
    }

    public function test_project_deletion_removes_only_its_review_records(): void
    {
        $doomed = $this->seedReview('delete-review');
        $spared = $this->seedReview('keep-review');
        $this->em->flush();
        self::assertNotNull($doomed->id);
        self::assertNotNull($spared->id);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed->item->project);

        self::assertNull($this->em->find(InboxReview::class, $doomed->id));
        self::assertInstanceOf(InboxReview::class, $this->em->find(InboxReview::class, $spared->id));
    }

    public function test_account_purger_accepts_a_detached_user_and_preserves_other_reviewers(): void
    {
        $doomed = $this->seedReview('purge-review');
        $spared = $this->seedReview('keep-purge-review');
        $this->em->flush();
        $owner = $doomed->item->project->owner;
        $this->em->clear();
        self::assertFalse($this->em->contains($owner));
        $purger = self::getContainer()->get(InboxReviewAccountPurger::class);
        self::assertInstanceOf(InboxReviewAccountPurger::class, $purger);
        $projects = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projects);
        self::assertGreaterThan($projects->deletionOrder(), $purger->deletionOrder());
        $purger->purge($owner, new AccountDeletionCleanup());

        self::assertNull($this->em->find(InboxReview::class, $doomed->id));
        self::assertInstanceOf(InboxReview::class, $this->em->find(InboxReview::class, $spared->id));
    }

    private function seedReview(string $slug): InboxReview
    {
        $owner = $this->owner($this->em, $slug);
        $project = $this->project($this->em, $owner, $slug);
        $document = $this->document($this->em, $project);
        $document->addVersion('# Review', '<h1>Review</h1>');
        $verdict = new Review($document->currentVersion(), Verdict::Approved, $owner, note: 'The retry behaviour is clear.');
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review the design', true);
        $item->state = InboxItemState::Done;
        $review = new InboxReview($item, $document);
        $review->verdict = InboxReviewVerdict::Approved;
        $review->note = $verdict->note;
        $review->reviewer = $owner;
        $review->submittedAt = new \DateTimeImmutable('2026-09-17 00:00:00', new \DateTimeZone('UTC'));
        $review->reviewedVersionNumber = 1;
        $review->documentReview = $verdict;
        $this->em->persist($verdict);
        $this->em->persist($item);
        $this->em->persist($review);

        return $review;
    }
}
