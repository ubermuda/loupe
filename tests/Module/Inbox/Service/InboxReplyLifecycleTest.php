<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Inbox\Entity\InboxReply;
use App\Module\Inbox\Service\InboxReplyAccountPurger;
use App\Module\Inbox\Service\InboxReplyExporter;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxReplyLifecycleTest extends KernelTestCase
{
    use InboxFixtures;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_export_and_deletion_stay_scoped_to_the_owner(bool $deleteProject): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'reply-lifecycle');
        $otherOwner = $this->owner($em, 'reply-spared');
        $project = $this->project($em, $owner, 'reply-lifecycle');
        $otherProject = $this->project($em, $otherOwner, 'reply-spared');
        $reply = new InboxReply($this->item($em, $project), $owner, 'My reply.', Uuid::v4());
        $otherReply = new InboxReply($this->item($em, $otherProject), $otherOwner, 'Their reply.', Uuid::v4());
        $em->persist($reply);
        $em->persist($otherReply);
        $em->flush();
        $replyId = (string) $reply->id;
        $otherReplyId = (string) $otherReply->id;
        $em->clear();

        $exporter = self::getContainer()->get(InboxReplyExporter::class);
        self::assertInstanceOf(InboxReplyExporter::class, $exporter);
        self::assertSame('inbox_replies.json', $exporter->filename());
        $export = iterator_to_array($exporter->export($owner), false);
        self::assertCount(1, $export);
        self::assertSame($replyId, $export[0]['id']);
        self::assertSame('My reply.', $export[0]['body']);
        if ($deleteProject) {
            $deleter = self::getContainer()->get(ProjectDeleter::class);
            self::assertInstanceOf(ProjectDeleter::class, $deleter);
            $storedProject = $em->find(Project::class, $project->id);
            self::assertInstanceOf(Project::class, $storedProject);
            $deleter->delete($storedProject);
        } else {
            self::assertFalse($em->contains($owner));
            $purger = self::getContainer()->get(InboxReplyAccountPurger::class);
            $projects = self::getContainer()->get(ProjectAccountPurger::class);
            self::assertInstanceOf(InboxReplyAccountPurger::class, $purger);
            self::assertInstanceOf(ProjectAccountPurger::class, $projects);
            self::assertGreaterThan($projects->deletionOrder(), $purger->deletionOrder());
            $purger->purge($owner, new AccountDeletionCleanup());
        }
        $em->clear();
        self::assertNull($em->find(InboxReply::class, $replyId));
        self::assertInstanceOf(InboxReply::class, $em->find(InboxReply::class, $otherReplyId));
    }
}
