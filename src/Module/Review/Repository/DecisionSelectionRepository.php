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
     * Every answer recorded on a document, grouped by decision id.
     *
     * A multi-choice block holds one row per chosen option, so every caller
     * reads a list. Returning one row per decision dropped the rest in silence.
     *
     * @return array<string, list<DecisionSelection>>
     */
    public function findByDocumentGroupedByDecisionId(Document $document): array
    {
        $selections = [];
        foreach ($this->findBy(['document' => $document], ['optionIndex' => 'ASC']) as $selection) {
            $selections[$selection->decisionId][] = $selection;
        }

        return $selections;
    }

    /** @return list<DecisionSelection> */
    public function findByDocumentAndDecisionId(Document $document, string $decisionId): array
    {
        return array_values($this->findBy(
            ['document' => $document, 'decisionId' => $decisionId],
            ['optionIndex' => 'ASC'],
        ));
    }
}
