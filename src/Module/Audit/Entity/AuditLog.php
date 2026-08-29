<?php

declare(strict_types=1);

namespace App\Module\Audit\Entity;

use App\Module\Audit\AuditActorInterface;
use App\Module\Audit\AuditCredentialInterface;
use App\Module\Audit\AuditLevel;
use App\Module\Audit\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One recorded audit event. Rows are appended by DoctrineAuditSink through the
 * DBAL connection rather than this mapping, which exists so the schema has a
 * single declared source and a reader can hydrate a row.
 *
 * The subject is a type/id pair with no association: a subject can be a
 * Document, a Comment, a Project or an ApiToken, and ResolveTargetEntityListener
 * maps one interface to exactly one class.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(name: 'idx_audit_log_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_audit_log_operation_occurred_at', columns: ['operation', 'occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_occurred_at', columns: ['occurred_at'])]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    public const int MAX_OPERATION_LENGTH = 100;
    public const int MAX_LABEL_LENGTH = 255;
    public const int MAX_SUBJECT_TYPE_LENGTH = 50;
    public const int MAX_SUBJECT_ID_LENGTH = 64;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        #[ORM\Column(length: self::MAX_OPERATION_LENGTH)]
        public string $operation,

        #[ORM\Column(length: 20, enumType: AuditLevel::class)]
        public AuditLevel $level,

        #[ORM\Column(length: 20)]
        public string $category,

        #[ORM\Column(length: 20)]
        public string $channel,

        /** Microsecond precision, because a burst of events inside one request must stay ordered. */
        #[ORM\Column(columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL')]
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),

        #[ORM\Column(type: Types::JSONB, options: ['default' => '{}'])]
        public array $context = [],

        /**
         * SET NULL rather than CASCADE: deleting an account must not erase the
         * trail of what it did, so the row outlives the actor it points at and
         * keeps only the label.
         */
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: AuditActorInterface::class)]
        public ?AuditActorInterface $actor = null,

        #[ORM\Column(length: self::MAX_LABEL_LENGTH, nullable: true)]
        public ?string $actorLabel = null,

        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: AuditCredentialInterface::class)]
        public ?AuditCredentialInterface $credential = null,

        #[ORM\Column(length: self::MAX_SUBJECT_TYPE_LENGTH, nullable: true)]
        public ?string $subjectType = null,

        #[ORM\Column(length: self::MAX_SUBJECT_ID_LENGTH, nullable: true)]
        public ?string $subjectId = null,
    ) {
    }
}
