<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReviewWithdrawalRepositoryTest extends KernelTestCase
{
    public function test_a_withdrawal_applies_only_to_the_preceding_verdict_even_after_another_approval(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User('Reviewer', 'withdrawal-'.uniqid().'@example.com', 'hashed');
        $project = new Project($owner, 'withdrawal-'.uniqid());
        $document = new Document($owner, $project, 'Design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $time = new \DateTimeImmutable('2026-09-17 00:00:00');
        $first = new Review($document->currentVersion(), Verdict::Approved, $owner, 1, $time);
        $second = new Review($document->currentVersion(), Verdict::ChangesRequested, $owner, 2, $time, 'Explain retries.');
        $withdrawal = new Review($document->currentVersion(), Verdict::Withdrawn, $owner, 3, $time);
        $latest = new Review($document->currentVersion(), Verdict::Approved, $owner, 4, $time);
        foreach ([$owner, $project, $document, $first, $second, $withdrawal, $latest] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $repository = self::getContainer()->get(ReviewRepository::class);
        self::assertInstanceOf(ReviewRepository::class, $repository);
        self::assertSame((string) $withdrawal->id, $repository->findWithdrawalOf($second)?->id?->toRfc4122());
        self::assertTrue(null === $repository->findWithdrawalOf($first), 'The earlier approval was superseded, not withdrawn.');
        self::assertTrue(null === $repository->findWithdrawalOf($latest), 'The latest approval still stands.');
        self::assertTrue(null === $repository->findWithdrawalOf($withdrawal), 'A withdrawal cannot itself be withdrawn.');
    }
}
