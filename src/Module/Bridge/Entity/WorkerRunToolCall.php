<?php

declare(strict_types=1);

namespace App\Module\Bridge\Entity;

use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One tool call of one worker run, as the bridge read it from the stream of
 * the worker. WorkerRunToolCallRepository writes the row with SQL, so a
 * repeated report of a call changes nothing.
 */
#[ORM\Entity(repositoryClass: WorkerRunToolCallRepository::class)]
#[ORM\Table(name: 'bridge_worker_run_tool_calls')]
#[ORM\UniqueConstraint(name: 'uniq_bridge_worker_run_tool_call_seq', columns: ['run_id', 'seq'])]
class WorkerRunToolCall
{
    public const int MAX_TOOL_LENGTH = 64;

    public const int MAX_BACKGROUND_ID_LENGTH = 64;

    public const int MAX_SIGNATURES = 20;

    public const int MAX_SIGNATURE_LENGTH = 120;

    public const int MAX_FULL_TEXT_LENGTH = 20000;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        #[ORM\Id]
        public readonly Uuid $id,

        // The retention sweep deletes runs with DQL, so only the database can remove the row.
        #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: WorkerRun::class)]
        public readonly WorkerRun $run,

        #[ORM\Column(name: 'seq')]
        public readonly int $seq,

        #[ORM\Column(name: 'tool', length: self::MAX_TOOL_LENGTH)]
        public readonly string $tool,

        #[ORM\Column(name: 'started_at')]
        public readonly \DateTimeImmutable $startedAt,

        /** Null when the stream holds no result of the call. */
        #[ORM\Column(name: 'duration_ms', type: Types::BIGINT, nullable: true)]
        public readonly ?int $durationMs,

        #[ORM\Column(name: 'is_error', nullable: true)]
        public readonly ?bool $isError,

        #[ORM\Column(name: 'in_subagent')]
        public readonly bool $inSubagent,

        #[ORM\Column(name: 'background_id', length: self::MAX_BACKGROUND_ID_LENGTH, nullable: true)]
        public readonly ?string $backgroundId,

        /** The background id of an earlier call that this call reads or waits on. */
        #[ORM\Column(name: 'waits_on', length: self::MAX_BACKGROUND_ID_LENGTH, nullable: true)]
        public readonly ?string $waitsOn,

        /** @var list<string> */
        #[ORM\Column(name: 'signatures', type: Types::JSON)]
        public readonly array $signatures,

        #[ORM\Column(name: 'full_text', type: Types::TEXT, nullable: true)]
        public readonly ?string $fullText,
    ) {
    }
}
