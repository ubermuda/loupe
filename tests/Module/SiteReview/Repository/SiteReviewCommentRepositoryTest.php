<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewCommentRepositoryTest extends KernelTestCase
{
    public function test_marking_addressed_leaves_no_pending_write_behind(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(SiteReviewCommentRepository::class);

        $owner = new User(fullName: 'Race', email: 'repo-race@example.com', password: 'x');
        $em->persist($owner);
        $project = new Project($owner, 'repo-race-site');
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'Body', 'https://example.com/');
        $em->persist($comment);
        $em->flush();

        self::assertTrue($repository->markAddressedIfPending($comment));
        self::assertSame(SiteReviewCommentStatus::Addressed, $comment->status);

        // A human resolves it afterwards. The entity must not still be dirty:
        // an unconditional write from the next flush is the race the method
        // exists to close, and callers need not own a transaction to be safe.
        $em->createQuery(
            'UPDATE '.SiteReviewComment::class.' c SET c.status = :resolved WHERE c.id = :id'
        )
            ->setParameter('resolved', SiteReviewCommentStatus::Resolved)
            ->setParameter('id', $comment->id, 'uuid')
            ->execute();
        $em->flush();

        $id = $comment->id;
        $em->clear();
        $fetched = $em->find(SiteReviewComment::class, $id);
        self::assertNotNull($fetched);
        self::assertSame(SiteReviewCommentStatus::Resolved, $fetched->status);
    }

    public function test_status_counts_cover_every_status(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(SiteReviewCommentRepository::class);

        $owner = new User(
            fullName: 'Counts',
            email: 'counts@example.com',
            password: 'x',
        );
        $em->persist($owner);
        $project = new Project($owner, 'counts-site');
        $em->persist($project);

        $position = 0;
        foreach ([
            SiteReviewCommentStatus::Pending,
            SiteReviewCommentStatus::Addressed,
            SiteReviewCommentStatus::Resolved,
        ] as $status) {
            $comment = new SiteReviewComment($project, $position++, 'Body', 'https://example.com/');
            $comment->status = $status;
            $em->persist($comment);
        }
        $em->flush();

        self::assertSame(
            ['pending' => 1, 'addressed' => 1, 'resolved' => 1],
            $repository->statusCountsForProject($project),
        );
        self::assertSame(1, $repository->countOpenForProject($project));
    }
}
