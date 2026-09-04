<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SetSectionApprovalCommand;
use App\Module\Review\Command\SetSectionApprovalHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\SectionApprovalReader;
use App\Module\Review\ValueObject\SectionApprovalSummary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SectionApprovalReaderTest extends KernelTestCase
{
    private const string MARKDOWN = "## Alpha\n\nAlpha body.\n\n## Beta\n\nBeta body.\n";

    public function test_an_approval_shows_on_the_version_it_was_given_on(): void
    {
        [$user, $document] = $this->seed();
        $this->approve($document, $user, 'heading-alpha');

        $summary = $this->read($document, $this->version($document, 1), $user);

        self::assertSame(1, $summary->approvedCount());
        self::assertArrayHasKey('heading-alpha', $summary->approvedByHeadingId);
    }

    public function test_an_older_version_never_shows_an_approval_given_after_it(): void
    {
        [$user, $document] = $this->seed();

        // Version 2 repeats version 1 word for word, so the digests match and
        // only the version guard can tell the two apart.
        $this->revise($document, self::MARKDOWN);
        $this->approve($document, $user, 'heading-alpha');

        self::assertSame(1, $this->read($document, $this->version($document, 2), $user)->approvedCount());
        self::assertSame(0, $this->read($document, $this->version($document, 1), $user)->approvedCount());
    }

    public function test_another_reader_sees_none_of_the_approvals(): void
    {
        [$user, $document] = $this->seed();
        $this->approve($document, $user, 'heading-alpha');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $other = new User(fullName: 'Other', email: 'other-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($other);
        $em->flush();

        self::assertSame(0, $this->read($document, $this->version($document, 1), $other)->approvedCount());
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

        return [$user, $create(new CreateDocumentCommand($project, 'Spec', self::MARKDOWN))];
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

    private function revise(Document $document, string $markdown): void
    {
        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, $markdown, 'Revised for the test.'));
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        $versions = self::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $version = $versions->findByNumber($document, $versionNumber);
        self::assertInstanceOf(DocumentVersion::class, $version);

        return $version;
    }

    private function read(Document $document, DocumentVersion $version, User $reader): SectionApprovalSummary
    {
        $reads = self::getContainer()->get(SectionApprovalReader::class);
        self::assertInstanceOf(SectionApprovalReader::class, $reads);

        return $reads($document, $version, new HeadingExtractor()->extract($version->renderedHtml), $reader);
    }
}
