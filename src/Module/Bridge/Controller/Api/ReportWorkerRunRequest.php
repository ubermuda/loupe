<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** One finished worker run, as the bridge reports it. */
final class ReportWorkerRunRequest
{
    /**
     * A process killed by a signal reports a negative code, so the range is
     * symmetric around the 0 to 255 a normal exit uses.
     */
    public const int MIN_EXIT_CODE = -255;

    public const int MAX_EXIT_CODE = 255;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $bridgeId = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $cardId = null,

        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $cardNumber = null,

        #[Assert\Length(max: WorkerRun::MAX_RULE_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $ruleName = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $startedAt = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $endedAt = null,

        #[Assert\Range(min: self::MIN_EXIT_CODE, max: self::MAX_EXIT_CODE)]
        public ?int $exitCode = null,

        #[Assert\Length(max: WorkerRun::MAX_FAILURE_REASON_LENGTH, normalizer: 'trim')]
        public ?string $failureReason = null,

        /** The cap is the server's own, so a bridge that stops applying V4's cap cannot widen the row. */
        #[Assert\Length(max: WorkerRun::MAX_OUTPUT_LENGTH)]
        #[Assert\NotNull]
        public ?string $output = null,
    ) {
    }

    /**
     * A run that never started says why, and a run that exited has nothing to
     * say. Read through failureReason(), so a blank string counts as absent and
     * cannot make a spawn failure look like a clean exit.
     */
    #[Assert\Callback]
    public function validateFailureReason(ExecutionContextInterface $context): void
    {
        if (null === $this->exitCode && null === $this->failureReason()) {
            $context->buildViolation('A run with no exit code needs a failure reason.')
                ->atPath('failureReason')
                ->addViolation();
        }

        if (null !== $this->exitCode && null !== $this->failureReason()) {
            $context->buildViolation('A run that exited has no failure reason.')
                ->atPath('failureReason')
                ->addViolation();
        }
    }

    /** Both stamps come from one bridge clock, so a run cannot end before it starts. */
    #[Assert\Callback]
    public function validateDuration(ExecutionContextInterface $context): void
    {
        if (null !== $this->startedAt && null !== $this->endedAt && $this->endedAt < $this->startedAt) {
            $context->buildViolation('A run cannot end before it starts.')
                ->atPath('endedAt')
                ->addViolation();
        }
    }

    public function bridgeId(): Uuid
    {
        return Uuid::fromString($this->bridgeId ?? throw new \LogicException('bridgeId is required after validation.'));
    }

    public function cardId(): Uuid
    {
        return Uuid::fromString($this->cardId ?? throw new \LogicException('cardId is required after validation.'));
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
}
