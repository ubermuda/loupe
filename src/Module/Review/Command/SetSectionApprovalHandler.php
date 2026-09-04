<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\SectionApproval;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\SectionApprovalRepository;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\SectionHasher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class SetSectionApprovalHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private SectionApprovalRepository $sectionApprovals,
        private HeadingExtractor $headings,
        private SectionHasher $hasher,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SetSectionApprovalCommand $command): void
    {
        $versionNumber = $this->em->wrapInTransaction(function () use ($command): int {
            // Two overlapping approvals of one section would both find no row and
            // both insert, tripping the unique index. The document is the only row
            // existing before the first approval, so it serialises them. The
            // version is read inside the lock too, or a revision landing mid-write
            // is hashed against text the reviewer never saw.
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($command->document);

            // Refused rather than resolved against the current version: a section
            // approved on the strength of text a revision has already replaced is
            // the failure this whole record exists to prevent.
            if ($version->versionNumber !== $command->displayedVersionNumber) {
                throw new DomainErrors(['headingId' => 'review.section.error.stale_version']);
            }

            $approval = $this->sectionApprovals->findOneByDocumentHeadingAndApprover(
                $command->document,
                $command->headingId,
                $command->reviewer,
            );

            // Only an approval needs the digest. A withdrawal that demanded one
            // would refuse to delete a row whose heading an in-place re-render
            // has since removed, and that row is the one worth removing.
            if (!$command->approved) {
                if (null !== $approval) {
                    $this->em->remove($approval);
                }
            } else {
                $hashes = $this->hasher->hashes(
                    $version->renderedHtml,
                    $this->headings->extract($version->renderedHtml),
                );
                $hash = $hashes[$command->headingId]
                    ?? throw new DomainErrors(['headingId' => 'review.section.error.unknown']);

                if (null === $approval) {
                    $this->em->persist(new SectionApproval(
                        document: $command->document,
                        headingId: $command->headingId,
                        contentHash: $hash,
                        approver: $command->reviewer,
                        versionNumber: $version->versionNumber,
                    ));
                } else {
                    $approval->contentHash = $hash;
                    $approval->versionNumber = $version->versionNumber;
                    $approval->approvedAt = new \DateTimeImmutable();
                }
            }

            $this->em->flush();

            return $version->versionNumber;
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback.
        $this->auditor->record(
            'review.section_approval_set',
            AuditOutcome::Success,
            [
                'documentId' => (string) $command->document->id,
                'headingId' => $command->headingId,
                'approved' => $command->approved,
                'versionNumber' => $versionNumber,
                'reviewerId' => (string) $command->reviewer->id,
            ],
            new AuditSubject('document', (string) $command->document->id),
        );
    }
}
