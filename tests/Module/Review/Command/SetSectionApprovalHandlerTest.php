<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\SectionApproval;
use App\Module\Review\Repository\SectionApprovalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SetSectionApprovalHandlerTest extends KernelTestCase
{
    private const string MARKDOWN = "## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body.\n";

    public function test_approving_a_section_records_it_and_withdrawing_removes_it(): void
    {
        [$user, $document] = $this->seed();
        $handler = $this->handler();

        $handler(new SetSectionApprovalCommand($document, $user, 'heading-alpha', true, 1));

        $approvals = $this->repository()->findByDocument($document);
        self::assertCount(1, $approvals);
        self::assertSame('heading-alpha', $approvals[0]->headingId);
        self::assertSame(1, $approvals[0]->versionNumber);
        self::assertSame(64, \strlen($approvals[0]->contentHash));

        $handler(new SetSectionApprovalCommand($document, $user, 'heading-alpha', false, 1));

        self::assertSame([], $this->repository()->findByDocument($document));
    }

    public function test_approving_the_same_section_twice_keeps_one_row(): void
    {
        [$user, $document] = $this->seed();
        $handler = $this->handler();

        $handler(new SetSectionApprovalCommand($document, $user, 'heading-alpha', true, 1));
        $handler(new SetSectionApprovalCommand($document, $user, 'heading-alpha', true, 1));

        self::assertCount(1, $this->repository()->findByDocument($document));
    }

    public function test_withdrawing_a_section_that_was_never_approved_is_a_no_op(): void
    {
        [$user, $document] = $this->seed();

        ($this->handler())(new SetSectionApprovalCommand($document, $user, 'heading-alpha', false, 1));

        self::assertSame([], $this->repository()->findByDocument($document));
    }

    public function test_withdrawing_a_section_whose_heading_is_gone_removes_the_row(): void
    {
        [$user, $document] = $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $em->persist(new SectionApproval(
            document: $document,
            headingId: 'heading-gone',
            contentHash: str_repeat('0', 64),
            approver: $user,
            versionNumber: 1,
        ));
        $em->flush();
        self::assertCount(1, $this->repository()->findByDocument($document));

        ($this->handler())(new SetSectionApprovalCommand($document, $user, 'heading-gone', false, 1));

        self::assertSame([], $this->repository()->findByDocument($document));
    }

    public function test_an_unknown_heading_is_refused(): void
    {
        [$user, $document] = $this->seed();

        $this->expectException(DomainErrors::class);
        ($this->handler())(new SetSectionApprovalCommand($document, $user, 'heading-nowhere', true, 1));
    }

    public function test_an_approval_aimed_at_an_older_version_is_refused(): void
    {
        [$user, $document] = $this->seed();

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, self::MARKDOWN, 'Second version.'));

        try {
            ($this->handler())(new SetSectionApprovalCommand($document, $user, 'heading-alpha', true, 1));
            self::fail('a stale version number must be refused');
        } catch (DomainErrors $e) {
            self::assertSame(['headingId' => 'review.section.error.stale_version'], $e->errors);
        }

        self::assertSame([], $this->repository()->findByDocument($document));
    }

    /** @return array{User, Document} */
    private function seed(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Reviewer', email: 'reviewer-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Spec', self::MARKDOWN));

        return [$user, $document];
    }

    private function handler(): SetSectionApprovalHandler
    {
        $handler = self::getContainer()->get(SetSectionApprovalHandler::class);
        self::assertInstanceOf(SetSectionApprovalHandler::class, $handler);

        return $handler;
    }

    private function repository(): SectionApprovalRepository
    {
        $repository = self::getContainer()->get(SectionApprovalRepository::class);
        self::assertInstanceOf(SectionApprovalRepository::class, $repository);

        return $repository;
    }
}
