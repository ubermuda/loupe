<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\SectionApproval;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SectionApproval>
 */
class SectionApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SectionApproval::class);
    }

    /**
     * Every approval recorded on a document, by any reviewer.
     *
     * @return list<SectionApproval>
     */
    public function findByDocument(Document $document): array
    {
        return array_values($this->findBy(['document' => $document]));
    }

    /**
     * One reviewer's approvals on a document, indexed by heading id.
     *
     * @return array<string, SectionApproval>
     */
    public function findByDocumentAndApproverIndexedByHeadingId(Document $document, User $approver): array
    {
        $approvals = [];
        foreach ($this->findBy(['document' => $document, 'approver' => $approver]) as $approval) {
            $approvals[$approval->headingId] = $approval;
        }

        return $approvals;
    }

    public function findOneByDocumentHeadingAndApprover(Document $document, string $headingId, User $approver): ?SectionApproval
    {
        return $this->findOneBy(['document' => $document, 'headingId' => $headingId, 'approver' => $approver]);
    }

    /**
     * Every approval this user recorded, newest first.
     *
     * @return iterable<SectionApproval>
     */
    public function streamByApprover(User $approver): iterable
    {
        return $this->createQueryBuilder('approval')
            ->andWhere('approval.approver = :approver')
            ->setParameter('approver', $approver)
            ->orderBy('approval.approvedAt', 'DESC')
            ->getQuery()
            ->toIterable();
    }
}
