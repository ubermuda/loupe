<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** How one launch of an interactive session went, as the bridge reports it. */
final class ReportInteractiveLaunchRequest
{
    public const array STATES = [WorkerRunState::Running->value, WorkerRunState::NotStarted->value];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $bridgeId = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $cardId = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: ReportWorkerRunRequest::MAX_CARD_NUMBER)]
        public ?int $cardNumber = null,

        #[Assert\Length(max: WorkerRun::MAX_RULE_NAME_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $ruleName = null,

        #[Assert\Choice(choices: self::STATES)]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $at = null,

        #[Assert\Length(max: WorkerRun::MAX_FAILURE_REASON_LENGTH, normalizer: 'trim')]
        public ?string $failureReason = null,
    ) {
    }

    #[Assert\Callback]
    public function validateFailureReason(ExecutionContextInterface $context): void
    {
        if (WorkerRunState::NotStarted->value === $this->state && null === $this->failureReason()) {
            $context->buildViolation('A launch that failed says why.')->atPath('failureReason')->addViolation();
        }

        if (WorkerRunState::Running->value === $this->state && null !== $this->failureReason()) {
            $context->buildViolation('A launch that runs has no failure reason.')->atPath('failureReason')->addViolation();
        }
    }

    public function state(): WorkerRunState
    {
        return WorkerRunState::from($this->state ?? throw new \LogicException('state is required after validation.'));
    }

    /** The columns carry no time zone, so the moment converts to UTC here. */
    public function at(): \DateTimeImmutable
    {
        return ($this->at ?? throw new \LogicException('at is required after validation.'))->setTimezone(new \DateTimeZone('UTC'));
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
