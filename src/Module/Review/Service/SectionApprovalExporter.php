<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Review\Repository\SectionApprovalRepository;

final readonly class SectionApprovalExporter implements UserDataExporterInterface
{
    public function __construct(
        private SectionApprovalRepository $sectionApprovals,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'section_approvals.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->sectionApprovals->streamByApprover($user) as $approval) {
            yield [
                'document' => $approval->document->title,
                'headingId' => $approval->headingId,
                'contentHash' => $approval->contentHash,
                'versionNumber' => $approval->versionNumber,
                'approvedAt' => $approval->approvedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
