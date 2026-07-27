<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SubmitReviewHandlerTest extends KernelTestCase
{
    /** @return array{User, Document} */
    private function createUserAndDocument(EntityManagerInterface $em, string $suffix): array
    {
        $user = new User(
            username: 'reviewer'.$suffix,
            fullName: 'Reviewer',
            email: 'reviewer'.$suffix.'@example.com',
            password: 'hashed-placeholder',
        );
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Auth PRD', '# Auth'));

        return [$user, $doc];
    }

    public function test_changes_requested_creates_review_and_transitions_status(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '1');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        $review = $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: Verdict::ChangesRequested->value,
        ));

        self::assertInstanceOf(Review::class, $review);
        self::assertSame(Verdict::ChangesRequested, $review->verdict);
        self::assertSame($reviewer, $review->reviewer);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::ChangesRequested, $freshDoc->status);

        // Verify a Review record was persisted on the current version
        $currentVersion = $freshDoc->currentVersion();
        /** @var \App\Module\Review\Repository\ReviewRepository $reviewRepo */
        $reviewRepo = $em->getRepository(Review::class);
        $savedReview = $reviewRepo->findOneBy(['version' => $currentVersion]);
        self::assertInstanceOf(Review::class, $savedReview);
        self::assertSame(Verdict::ChangesRequested, $savedReview->verdict);
    }

    public function test_approved_creates_review_and_transitions_status(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '2');

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        $review = $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: Verdict::Approved->value,
        ));

        self::assertInstanceOf(Review::class, $review);
        self::assertSame(Verdict::Approved, $review->verdict);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);
        self::assertSame(DocumentStatus::Approved, $freshDoc->status);
    }

    public function test_an_unrecognised_verdict_value_throws_domain_errors(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $reviewer */
        /** @var Document $doc */
        [$reviewer, $doc] = $this->createUserAndDocument($em, '3');

        /** @var SubmitReviewHandler $handler */
        $handler = self::getContainer()->get(SubmitReviewHandler::class);

        $this->expectException(DomainErrors::class);

        $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $doc,
            verdict: 'not-a-real-verdict',
        ));
    }
}
