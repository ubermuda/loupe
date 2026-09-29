<?php

declare(strict_types=1);

namespace App\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisionAnswer>
 */
class DecisionAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisionAnswer::class);
    }

    public function findOneByDocumentAndDecisionId(Document $document, string $decisionId): ?DecisionAnswer
    {
        return $this->findOneBy(['document' => $document, 'decisionId' => $decisionId]);
    }

    /** @return array<string, DecisionAnswer> */
    public function findByDocumentIndexedByDecisionId(Document $document): array
    {
        $answers = [];
        foreach ($this->findBy(['document' => $document]) as $answer) {
            $answers[$answer->decisionId] = $answer;
        }

        return $answers;
    }

    /** @return iterable<DecisionAnswer> */
    public function streamByAnsweredBy(User $user): iterable
    {
        return $this->createQueryBuilder('answer')
            ->andWhere('answer.answeredBy = :user')
            ->setParameter('user', $user)
            ->orderBy('answer.updatedAt', 'DESC')
            ->getQuery()
            ->toIterable();
    }
}
