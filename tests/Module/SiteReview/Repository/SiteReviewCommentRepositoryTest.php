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
    public function test_submitted_count_covers_every_status_except_draft(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repository = static::getContainer()->get(SiteReviewCommentRepository::class);

        $owner = new User(
            username: 'counts@example.com',
            fullName: 'Counts',
            email: 'counts@example.com',
            password: 'x',
        );
        $em->persist($owner);
        $project = new Project($owner, 'counts-site');
        $em->persist($project);

        $position = 0;
        foreach ([
            SiteReviewCommentStatus::Draft,
            SiteReviewCommentStatus::Pending,
            SiteReviewCommentStatus::Addressed,
            SiteReviewCommentStatus::Resolved,
        ] as $status) {
            $comment = new SiteReviewComment($project, $position++, 'Body', '', '', 'https://example.com/');
            $comment->status = $status;
            $em->persist($comment);
        }
        $em->flush();

        // Three of the four statuses count; the draft is the reviewer's own
        // unsent scratch and must not show up in the nav pill.
        self::assertSame(3, $repository->countSubmittedForProject($project));
        self::assertSame(1, $repository->countOpenForProject($project));
    }
}
