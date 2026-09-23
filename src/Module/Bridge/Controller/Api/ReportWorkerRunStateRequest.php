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
    public const array DROP_REASONS = ['shutdown', 'rule_dead'];

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
    ) {
    }

    /** @return list<string> */
    public static function bridgeStates(): array
    {
        return array_values(array_map(
            static fn (WorkerRunState $state): string => $state->value,
            array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => !$state->isInferred()),
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

    /**
     * An outcome follows the pairing rules of the finished run report, and its
     * state must be the one its exit code implies.
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

        if (WorkerRunState::fromExitCode($this->exitCode) !== $state) {
            $context->buildViolation('The exit code does not match the state.')->atPath('exitCode')->addViolation();
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
