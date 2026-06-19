<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Query;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewBatch;
use App\Module\SiteReview\Query\BatchNotFound;
use App\Module\SiteReview\Query\GetSiteReview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class GetSiteReviewTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GetSiteReview $getSiteReview;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $getSiteReview = self::getContainer()->get(GetSiteReview::class);
        self::assertInstanceOf(GetSiteReview::class, $getSiteReview);
        $this->getSiteReview = $getSiteReview;
    }

    public function test_returns_owned_batch_comments_in_order(): void
    {
        $owner = $this->user('o@example.com');

        $batch = new SiteReviewBatch($owner);
        $batch->addComment('first', '.a', 'A', 'https://app/x');
        $batch->addComment('second', '.b', 'B', 'https://app/y');
        $this->em->persist($batch);
        $this->em->flush();

        $id = $batch->id;
        self::assertNotNull($id);

        $result = ($this->getSiteReview)($id, $owner);

        self::assertNotSame('', $result['createdAt']);
        self::assertCount(2, $result['comments']);
        self::assertSame('first', $result['comments'][0]['body']);
        self::assertSame('.a', $result['comments'][0]['selector']);
        self::assertSame('A', $result['comments'][0]['text']);
        self::assertSame('https://app/y', $result['comments'][1]['url']);
        self::assertSame('second', $result['comments'][1]['body']);
    }

    public function test_other_users_batch_is_not_found(): void
    {
        $owner = $this->user('o2@example.com');
        $other = $this->user('x2@example.com');

        $batch = new SiteReviewBatch($owner);
        $batch->addComment('x', '.a', 'A', 'https://app/x');
        $this->em->persist($batch);
        $this->em->flush();

        $id = $batch->id;
        self::assertNotNull($id);

        $this->expectException(BatchNotFound::class);
        ($this->getSiteReview)($id, $other);
    }

    public function test_throws_for_unknown_id(): void
    {
        $owner = $this->user('o3@example.com');

        $this->expectException(BatchNotFound::class);
        ($this->getSiteReview)(Uuid::v7(), $owner);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(
            username: $email,
            fullName: 'U',
            email: $email,
            password: 'hashed',
        );
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
