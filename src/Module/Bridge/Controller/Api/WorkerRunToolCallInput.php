<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** One tool call of a worker run. */
final class WorkerRunToolCallInput
{
    /** The largest value of the integer column. */
    public const int MAX_SEQ = 2147483647;

    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: self::MAX_SEQ)]
        public ?int $seq = null,

        #[Assert\Length(max: WorkerRunToolCall::MAX_TOOL_LENGTH)]
        #[Assert\NotBlank]
        public ?string $tool = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $startedAt = null,

        /** Null when the stream holds no result of the call. */
        #[Assert\PositiveOrZero]
        public ?int $durationMs = null,
        public ?bool $isError = null,

        #[Assert\NotNull]
        public ?bool $inSubagent = null,

        #[Assert\Length(max: WorkerRunToolCall::MAX_BACKGROUND_ID_LENGTH)]
        public ?string $backgroundId = null,

        #[Assert\Length(max: WorkerRunToolCall::MAX_BACKGROUND_ID_LENGTH)]
        public ?string $waitsOn = null,

        /** @var list<string>|null */
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: WorkerRunToolCall::MAX_SIGNATURE_LENGTH)])]
        #[Assert\Count(max: WorkerRunToolCall::MAX_SIGNATURES)]
        #[Assert\NotNull]
        public ?array $signatures = null,

        #[Assert\Length(max: WorkerRunToolCall::MAX_FULL_TEXT_LENGTH)]
        public ?string $fullText = null,
    ) {
    }

    #[Assert\Callback]
    public function validateSignatures(ExecutionContextInterface $context): void
    {
        if (null !== $this->signatures && !array_is_list($this->signatures)) {
            $context->buildViolation('The signatures are a list.')->atPath('signatures')->addViolation();
        }
    }

    public function report(): WorkerRunToolCallReport
    {
        $startedAt = $this->startedAt ?? throw new \LogicException('startedAt is required after validation.');

        return new WorkerRunToolCallReport(
            seq: $this->seq ?? throw new \LogicException('seq is required after validation.'),
            tool: $this->tool ?? throw new \LogicException('tool is required after validation.'),
            startedAt: $startedAt->setTimezone(new \DateTimeZone('UTC')),
            durationMs: $this->durationMs,
            isError: $this->isError,
            inSubagent: $this->inSubagent ?? throw new \LogicException('inSubagent is required after validation.'),
            backgroundId: $this->backgroundId,
            waitsOn: $this->waitsOn,
            signatures: array_values(array_map(strval(...), $this->signatures ?? [])),
            fullText: $this->fullText,
        );
    }
}
