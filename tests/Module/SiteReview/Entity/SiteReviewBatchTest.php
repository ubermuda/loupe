<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\SiteReviewBatch;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewBatchTest extends KernelTestCase
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
        $batch->addComment('rename this', '.title', 'Heading', 'https://app.localhost/y');
        $em->persist($batch);
        $em->flush();

        $id = $batch->id;
        self::assertNotNull($id);
        $em->clear();

        $repo = static::getContainer()->get(SiteReviewBatchRepository::class);
        $fetched = $repo->findOneByIdAndOwner($id, $owner);
        self::assertNotNull($fetched);
        self::assertNull($repo->findOneByIdAndOwner($id, $other));
        self::assertCount(2, $fetched->comments);
        $ordered = $fetched->comments->toArray();
        self::assertSame([0, 1], array_map(static fn (SiteReviewComment $c) => $c->position, $ordered));
        self::assertSame('too big', $ordered[0]->body);
        self::assertSame('rename this', $ordered[1]->body);
    }
}
