<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SiteReviewRepository $siteReviews;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(SiteReviewRepository::class);
        self::assertInstanceOf(SiteReviewRepository::class, $repo);
        $this->siteReviews = $repo;
    }

    public function test_find_for_project_fetch_joins_comments_without_duplicating_reviews(): void
    {
        $project = $this->project('sr-fetch-join@example.com');

        $reviewWithComments = new SiteReview($project);
        $reviewWithComments->addComment('too small', '.btn-a', 'Save', 'https://app/a');
        $reviewWithComments->addComment('unclear', '.btn-b', 'Cancel', 'https://app/b');
        // Only one in-progress review is allowed per project (uniq_site_review_in_progress).
        $reviewWithComments->markSubmitted();
        $this->em->persist($reviewWithComments);

        // A review with zero comments must still appear (LEFT JOIN, not INNER).
        $reviewWithoutComments = new SiteReview($project);
        $this->em->persist($reviewWithoutComments);

        $this->em->flush();
        $this->em->clear();

        $found = $this->siteReviews->findForProject($project);

        self::assertCount(2, $found);

        $withComments = null;
        $withoutComments = null;
        foreach ($found as $review) {
            if ((string) $review->id === (string) $reviewWithComments->id) {
                $withComments = $review;
            }
            if ((string) $review->id === (string) $reviewWithoutComments->id) {
                $withoutComments = $review;
            }
        }

        self::assertNotNull($withComments);
        self::assertNotNull($withoutComments);
        self::assertCount(2, $withComments->comments);
        self::assertCount(0, $withoutComments->comments);

        // The comments' own OrderBy(position ASC) mapping still applies to the
        // fetch-joined collection.
        $texts = array_map(static fn ($c) => $c->text, $withComments->comments->toArray());
        self::assertSame(['Save', 'Cancel'], $texts);
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'test-site');
        $this->em->persist($project);

        return $project;
    }
}
