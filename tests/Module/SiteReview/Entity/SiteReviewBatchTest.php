<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewBatch;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SiteReviewBatchTest extends WebTestCase
{
    public function test_persist_and_scope_by_owner(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'o', fullName: 'O', email: 'o@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        $other = new User(username: 'x', fullName: 'X', email: 'x@example.com', password: 'x');
        $other->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($owner);
        $em->persist($other);

        $batch = new SiteReviewBatch($owner);
        $batch->addComment('too big', '.card', 'Save', 'https://app.localhost/x');
        $em->persist($batch);
        $em->flush();

        $id = $batch->id;
        self::assertNotNull($id);
        $em->clear();

        $repo = static::getContainer()->get(SiteReviewBatchRepository::class);
        $fetched = $repo->findOneByIdAndOwner($id, $owner);
        self::assertNotNull($fetched);
        self::assertNull($repo->findOneByIdAndOwner($id, $other));
        self::assertCount(1, $fetched->comments);
    }
}
