<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One state of one worker run, as the bridge reports it. Every report carries
 * the card and the rule, so the first report the server reads can create the
 * run.
 */
final class ReportWorkerRunStateRequest
{
    /** The bridge sends these when a run is dropped. */
    public const array DROP_REASONS = ['shutdown', 'rule_dead', 'reload'];

    /** The status a worker gives in its structured result. */
    public const array RESULT_STATUSES = ['finished', 'blocked', 'unfinished'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $bridgeId = null,

        #[Assert\Choice(callback: 'bridgeStates')]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $at = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $cardId = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: ReportWorkerRunRequest::MAX_CARD_NUMBER)]
        public ?int $cardNumber = null,

        #[Assert\Length(max: WorkerRun::MAX_RULE_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $ruleName = null,

        #[Assert\Uuid]
        public ?string $sessionId = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,

        #[Assert\Range(min: ReportWorkerRunRequest::MIN_EXIT_CODE, max: ReportWorkerRunRequest::MAX_EXIT_CODE)]
        public ?int $exitCode = null,
        public ?bool $hasResult = null,

        #[Assert\Length(max: WorkerRun::MAX_FAILURE_REASON_LENGTH, normalizer: 'trim')]
        public ?string $failureReason = null,

        #[Assert\Length(max: WorkerRun::MAX_OUTPUT_LENGTH)]
        public ?string $output = null,

        // The four fields below are checked for shape. The run holds no column for them.
        #[Assert\Length(max: 100)]
        public ?string $askId = null,

        #[Assert\Uuid]
        public ?string $replacedBy = null,

        #[Assert\Positive]
        public ?int $maxChain = null,

        #[Assert\Choice(choices: self::DROP_REASONS)]
        public ?string $reason = null,

        #[Assert\Choice(choices: self::RESULT_STATUSES)]
        public ?string $resultStatus = null,
        /** @var array<mixed>|null */
        public ?array $resultFields = null,

        /** The id the bridge gave the run that this run resumes. */
        #[Assert\Uuid]
        public ?string $continues = null,

        #[Assert\Range(min: 0, max: WorkerRun::MAX_RESUME_COUNT)]
        public ?int $resumeIndex = null,

        #[Assert\Range(min: 0, max: WorkerRun::MAX_RESUME_COUNT)]
        public ?int $resumeCap = null,

        #[Assert\Length(max: WorkerRun::MAX_CARD_COLUMN_LENGTH)]
        public ?string $cardColumn = null,

        #[Assert\Length(max: WorkerRun::MAX_RESUME_SKIPPED_LENGTH)]
        public ?string $resumeSkipped = null,
    ) {
    }

    /** @return list<string> */
    public static function bridgeStates(): array
    {
        return array_values(array_map(
            static fn (WorkerRunState $state): string => $state->value,
            array_filter(
                WorkerRunState::cases(),
                static fn (WorkerRunState $state): bool => !$state->isInferred() && WorkerRunState::Closed !== $state,
            ),
        ));
    }

    #[Assert\Callback]
    public function validateRunning(ExecutionContextInterface $context): void
    {
        if (WorkerRunState::Running->value !== $this->state) {
            return;
        }

        if (null === $this->sessionId || '' === $this->sessionId) {
            $context->buildViolation('A running run names its session.')->atPath('sessionId')->addViolation();
        }

        if (null === $this->startedAt) {
            $context->buildViolation('A running run says when it started.')->atPath('startedAt')->addViolation();
        }
    }

    #[Assert\Callback]
    public function validateResultFields(ExecutionContextInterface $context): void
    {
        if (null === $this->resultFields || [] === $this->resultFields) {
            return;
        }

        if (array_is_list($this->resultFields)) {
            $context->buildViolation('The result fields are an object.')->atPath('resultFields')->addViolation();

            return;
        }

        if (\strlen(json_encode($this->resultFields, \JSON_THROW_ON_ERROR)) > WorkerRun::MAX_RESULT_FIELDS_BYTES) {
            $context->buildViolation('The result fields are too large.')->atPath('resultFields')->addViolation();
        }
    }

    /**
     * An outcome follows the pairing rules of the finished run report, and its
     * state must be the one its exit code, result flag and status imply. So a
     * clean exit with no result is no-result, never succeeded. Gave-up stands
     * in for any outcome that the bridge would resume.
     */
    #[Assert\Callback]
    public function validateOutcome(ExecutionContextInterface $context): void
    {
        $state = WorkerRunState::tryFrom($this->state ?? '');
        if (null === $state || !$state->isOutcome()) {
            return;
        }

        if (null === $this->endedAt) {
            $context->buildViolation('An outcome says when the run ended.')->atPath('endedAt')->addViolation();
        }

        if (null === $this->output) {
            $context->buildViolation('An outcome carries the output, which may be empty.')->atPath('output')->addViolation();
        }

        if (null === $this->exitCode && null === $this->failureReason()) {
            $context->buildViolation('A run with no exit code needs a failure reason.')->atPath('failureReason')->addViolation();
        }

        if (null !== $this->exitCode && null !== $this->failureReason()) {
            $context->buildViolation('A run that exited has no failure reason.')->atPath('failureReason')->addViolation();
        }

        if (null === $this->exitCode && null !== $this->hasResult) {
            $context->buildViolation('A run with no exit code has no result flag.')->atPath('hasResult')->addViolation();
        }

        if (null !== $this->resultStatus && true !== $this->hasResult) {
            $context->buildViolation('A result status needs the result flag.')->atPath('resultStatus')->addViolation();
        }

        $implied = WorkerRunState::fromOutcome($this->exitCode, $this->hasResult, $this->resultStatus);
        $matches = WorkerRunState::GaveUp === $state
            ? \in_array($implied, [WorkerRunState::Failed, WorkerRunState::NoResult, WorkerRunState::Unfinished], true)
            : $implied === $state;
        if (!$matches) {
            $context->buildViolation('The exit code, the result flag and the status do not match the state.')->atPath('exitCode')->addViolation();
        }

        if (null !== $this->startedAt && null !== $this->endedAt && $this->endedAt < $this->startedAt) {
            $context->buildViolation('A run cannot end before it starts.')->atPath('endedAt')->addViolation();
        }
    }

    public function state(): WorkerRunState
    {
        return WorkerRunState::from($this->state ?? throw new \LogicException('state is required after validation.'));
    }

    /** The columns carry no time zone, so every moment converts to UTC here. */
    public function at(): \DateTimeImmutable
    {
        return self::utc($this->at ?? throw new \LogicException('at is required after validation.'));
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return null === $this->startedAt ? null : self::utc($this->startedAt);
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return null === $this->endedAt ? null : self::utc($this->endedAt);
    }

    public function bridgeId(): Uuid
    {
        return Uuid::fromString($this->bridgeId ?? throw new \LogicException('bridgeId is required after validation.'));
    }

    public function cardId(): Uuid
    {
        return Uuid::fromString($this->cardId ?? throw new \LogicException('cardId is required after validation.'));
    }

    public function continues(): ?Uuid
    {
        return null === $this->continues || '' === $this->continues ? null : Uuid::fromString($this->continues);
    }

    /** @return array<string, mixed>|null */
    public function resultFields(): ?array
    {
        if (null === $this->resultFields) {
            return null;
        }

        $fields = [];
        foreach ($this->resultFields as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    public function sessionId(): ?Uuid
    {
        return null === $this->sessionId || '' === $this->sessionId ? null : Uuid::fromString($this->sessionId);
    }

    /** Trimmed, because the length constraint measured the trimmed value. */
    public function ruleName(): string
    {
        return trim($this->ruleName ?? '');
    }

    public function failureReason(): ?string
    {
        $reason = trim($this->failureReason ?? '');

        return '' === $reason ? null : $reason;
    }

    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
