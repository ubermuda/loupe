<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\SectionApprovalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A revision keeps an approval whose section still reads the same, and drops
 * every other one. This is the whole point of storing a digest beside the
 * heading id, so it is covered against the real renderer.
 */
final class ReviseDocumentSectionApprovalTest extends KernelTestCase
{
    private const string V1 = "## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body.\n";

    public function test_an_untouched_section_carries_forward_and_an_edited_one_drops(): void
    {
        [$user, $document] = $this->seed();
        $this->approve($document, $user, 'heading-alpha');
        $this->approve($document, $user, 'heading-beta');

        $summary = $this->revise($document, "## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body rewritten.\n");

        self::assertSame(1, $summary['sectionsCarried']);
        self::assertSame(1, $summary['sectionsDropped']);

        $surviving = $this->repository()->findByDocument($document);
        self::assertCount(1, $surviving);
        self::assertSame('heading-alpha', $surviving[0]->headingId);
        // The approval keeps the version it was given on: it is the same
        // approval carried forward, not a new one made by the revision.
        self::assertSame(1, $surviving[0]->versionNumber);
    }

    public function test_a_renamed_heading_drops_its_approval_even_when_the_body_is_identical(): void
    {
        [$user, $document] = $this->seed();
        $this->approve($document, $user, 'heading-beta');

        $summary = $this->revise($document, "## Alpha\n\nAlpha body.\n\n## Gamma\n\nBeta body.\n");

        self::assertSame(0, $summary['sectionsCarried']);
        self::assertSame(1, $summary['sectionsDropped']);
        self::assertSame([], $this->repository()->findByDocument($document));
    }

    public function test_a_revision_that_changes_nothing_carries_every_approval(): void
    {
        [$user, $document] = $this->seed();
        $this->approve($document, $user, 'heading-alpha');
        $this->approve($document, $user, 'heading-beta');

        $summary = $this->revise($document, self::V1);

        self::assertSame(2, $summary['sectionsCarried']);
        self::assertSame(0, $summary['sectionsDropped']);
        self::assertCount(2, $this->repository()->findByDocument($document));
    }

    public function test_a_document_with_no_approvals_reports_zero_on_both_counts(): void
    {
        [, $document] = $this->seed();

        $summary = $this->revise($document, "## Alpha\n\nSomething else entirely.\n");

        self::assertSame(0, $summary['sectionsCarried']);
        self::assertSame(0, $summary['sectionsDropped']);
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

        return [$user, $create(new CreateDocumentCommand($project, 'Spec', self::V1))];
    }

    private function approve(Document $document, User $user, string $headingId): void
    {
        $handler = self::getContainer()->get(SetSectionApprovalHandler::class);
        self::assertInstanceOf(SetSectionApprovalHandler::class, $handler);
        $handler(new SetSectionApprovalCommand(
            $document,
            $user,
            $headingId,
            true,
            $document->currentVersion()->versionNumber,
        ));
    }

    /** @return array{carried: int, orphaned: int, sectionsCarried: int, sectionsDropped: int} */
    private function revise(Document $document, string $markdown): array
    {
        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);

        return $revise(new ReviseDocumentCommand($document, $markdown, 'Revised for the test.'));
    }

    private function repository(): SectionApprovalRepository
    {
        $repository = self::getContainer()->get(SectionApprovalRepository::class);
        self::assertInstanceOf(SectionApprovalRepository::class, $repository);

        return $repository;
    }
}
