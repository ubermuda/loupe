<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewReply;
use App\Module\SiteReview\Service\SiteReviewReplyAccountPurger;
use App\Module\SiteReview\Service\SiteReviewReplyExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SiteReviewReplyLifecycleTest extends KernelTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_export_and_deletion_preserve_another_owners_replies(bool $deleteProject): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User('Riley Chen', 'site-reply-lifecycle@example.com', 'x');
        $otherOwner = new User('Alex Kim', 'site-reply-spared@example.com', 'x');
        $project = new Project($owner, 'My feedback');
        $otherProject = new Project($otherOwner, 'Other feedback');
        $comment = new SiteReviewComment($project, 0, 'My capture', 'https://example.com');
        $otherComment = new SiteReviewComment($otherProject, 0, 'Other capture', 'https://example.net');
        $reply = new SiteReviewReply($comment, $owner, 'My reply', Uuid::v4());
        $otherReply = new SiteReviewReply($otherComment, $otherOwner, 'Their reply', Uuid::v4());
        foreach ([$owner, $otherOwner, $project, $otherProject, $comment, $otherComment, $reply, $otherReply] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();
        $exporter = self::getContainer()->get(SiteReviewReplyExporter::class);
        self::assertInstanceOf(SiteReviewReplyExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($owner), false);
        self::assertCount(1, $rows);
        self::assertSame((string) $reply->id, $rows[0]['id']);
        self::assertSame('My reply', $rows[0]['body']);
        if ($deleteProject) {
            $storedProject = $em->find(Project::class, $project->id);
            self::assertInstanceOf(Project::class, $storedProject);
            self::getContainer()->get(ProjectDeleter::class)->delete($storedProject);
        } else {
            self::assertFalse($em->contains($owner));
            $purger = self::getContainer()->get(SiteReviewReplyAccountPurger::class);
            self::assertInstanceOf(SiteReviewReplyAccountPurger::class, $purger);
            $projects = self::getContainer()->get(ProjectAccountPurger::class);
            self::assertInstanceOf(ProjectAccountPurger::class, $projects);
            self::assertGreaterThan($projects->deletionOrder(), $purger->deletionOrder());
            $purger->purge($owner, new AccountDeletionCleanup());
        }
        $em->clear();
        self::assertNull($em->find(SiteReviewReply::class, $reply->id));
        self::assertInstanceOf(SiteReviewReply::class, $em->find(SiteReviewReply::class, $otherReply->id));
    }
}
