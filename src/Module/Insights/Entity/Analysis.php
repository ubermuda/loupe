<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One analysis that an agent runs on a work request. The work request and the
 * report document are scalar ids, so this module needs no foreign key into
 * Bridge or Review. The cost comes from the fact rows of the subject on read.
 */
#[ORM\Entity(repositoryClass: AnalysisRepository::class)]
#[ORM\Table(name: 'insights_analyses')]
class Analysis
{
    public const string SUBJECT_TYPE = 'analysis';

    public const int MAX_QUESTION_LENGTH = 2000;

    public const int MAX_REASON_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(name: 'work_request_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $workRequestId = null;

    #[ORM\Column(name: 'document_id', type: UuidType::NAME, nullable: true)]
    public ?Uuid $documentId = null;

    #[ORM\Column(name: 'state', length: 20, enumType: AnalysisState::class)]
    public AnalysisState $state = AnalysisState::Waiting;

    #[ORM\Column(name: 'reason', length: self::MAX_REASON_LENGTH, nullable: true)]
    public ?string $reason = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    /** @var array<mixed> */
    #[ORM\Column(name: 'scope', type: Types::JSON)]
    private array $scopeData = [];

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public Project $project,

        #[ORM\Column(name: 'topic', length: 20, enumType: AnalysisTopic::class)]
        public AnalysisTopic $topic,
        public AnalysisScope $scope {
            get => AnalysisScope::fromArray($this->scopeData);
            set {
                $this->scopeData = $value->toArray();
            }
        },

        #[ORM\Column(name: 'question', type: Types::TEXT, nullable: true)]
        public ?string $question,

        #[ORM\Column(name: 'model', length: 64)]
        public string $model,

        #[ORM\Column(name: 'effort', length: 16)]
        public string $effort,

        #[ORM\Column(name: 'created_at')]
        public \DateTimeImmutable $createdAt,
    )
    {
    }

    public function start(): void
    {
        if (AnalysisState::Waiting !== $this->state) {
            throw new \LogicException('Only a waiting analysis starts.');
        }
        $this->state = AnalysisState::Running;
    }

    public function complete(Uuid $documentId, \DateTimeImmutable $now): void
    {
        $this->finish(AnalysisState::Done, null, $now);
        $this->documentId = $documentId;
    }

    public function fail(string $reason, \DateTimeImmutable $now): void
    {
        $this->finish(AnalysisState::Failed, $reason, $now);
    }

    /** A paused analysis has no live work, and it can still take a report. */
    public function pause(string $reason, \DateTimeImmutable $now): void
    {
        if (AnalysisState::Waiting !== $this->state && AnalysisState::Running !== $this->state) {
            throw new \LogicException('Only a waiting or running analysis pauses.');
        }
        $this->state = AnalysisState::Paused;
        $this->reason = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        $this->finishedAt = $now;
    }

    private function finish(AnalysisState $state, ?string $reason, \DateTimeImmutable $now): void
    {
        if ($this->state->isFinished()) {
            throw new \LogicException('A finished analysis stays finished.');
        }
        $this->state = $state;
        $this->reason = null === $reason ? null : mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        $this->finishedAt = $now;
    }
}
