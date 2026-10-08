<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** How one launch of an interactive session went, as the bridge reports it. An interactive run is about a card. */
final class ReportInteractiveLaunchRequest
{
    public const array STATES = [WorkerRunState::Running->value, WorkerRunState::NotStarted->value];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $bridgeId = null,

        #[Assert\Choice(choices: [WorkSubject::CARD])]
        #[Assert\NotBlank]
        public ?string $subjectType = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $subjectId = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 1, max: WorkerRun::MAX_CARD_NUMBER)]
        public ?int $cardNumber = null,

        /** The kind of the work request, or the name of the session when it runs no work request. */
        #[Assert\Length(max: WorkerRun::MAX_WORK_KIND_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $workKind = null,

        #[Assert\Uuid]
        public ?string $workRequestId = null,

        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: WorkRequest::RULE_ID_PATTERN)]
        public ?string $ruleId = null,

        #[Assert\Choice(choices: self::STATES)]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\NotNull]
        public ?\DateTimeImmutable $at = null,

        #[Assert\Length(max: WorkerRun::MAX_FAILURE_REASON_LENGTH, normalizer: 'trim')]
        public ?string $failureReason = null,

        /** The four harness fields are null from a bridge that predates harnesses. A null keeps the stored value. */
        #[Assert\Length(max: WorkerRun::MAX_HARNESS_LENGTH)]
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: WorkerRun::HARNESS_PATTERN)]
        public ?string $harness = null,

        #[Assert\Length(max: WorkerRun::MAX_ACCOUNT_LENGTH)]
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: WorkerRun::ACCOUNT_PATTERN)]
        public ?string $account = null,

        #[Assert\Length(max: WorkerRun::MAX_MODEL_LENGTH)]
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: WorkerRun::MODEL_PATTERN)]
        public ?string $model = null,

        #[Assert\Length(max: WorkerRun::MAX_HARNESS_SESSION_ID_LENGTH)]
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Regex(pattern: WorkerRun::HARNESS_SESSION_ID_PATTERN)]
        public ?string $harnessSessionId = null,
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

    /** The card, because the subject type is always a card after validation. */
    public function cardId(): Uuid
    {
        return Uuid::fromString($this->subjectId ?? throw new \LogicException('subjectId is required after validation.'));
    }

    /** Trimmed, because the length constraint measured the trimmed value. */
    public function workKind(): string
    {
        return trim($this->workKind ?? '');
    }

    public function workRequestId(): ?Uuid
    {
        return null === $this->workRequestId || '' === $this->workRequestId ? null : Uuid::fromString($this->workRequestId);
    }

    public function failureReason(): ?string
    {
        $reason = trim($this->failureReason ?? '');

        return '' === $reason ? null : $reason;
    }
}
