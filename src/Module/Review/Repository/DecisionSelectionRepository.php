<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisionSelection>
 */
class DecisionSelectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisionSelection::class);
    }

    /**
     * Every answer recorded on a document, indexed by decision id.
     *
     * @return array<string, DecisionSelection>
     */
    public function findByDocumentIndexedByDecisionId(Document $document): array
    {
        $selections = [];
        foreach ($this->findBy(['document' => $document]) as $selection) {
            $selections[$selection->decisionId] = $selection;
        }

        return $selections;
    }

    public function findOneByDocumentAndDecisionId(Document $document, string $decisionId): ?DecisionSelection
    {
        return $this->findOneBy(['document' => $document, 'decisionId' => $decisionId]);
    }
}
