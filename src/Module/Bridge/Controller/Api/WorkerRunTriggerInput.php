<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunTrigger;
use Symfony\Component\Validator\Constraints as Assert;

/** The event that made the bridge queue a run. The bridge omits each field the event does not have. */
final class WorkerRunTriggerInput
{
    public function __construct(
        #[Assert\Length(max: WorkerRun::MAX_TRIGGER_EVENT_TYPE_LENGTH)]
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: WorkerRun::TRIGGER_EVENT_TYPE_PATTERN)]
        public ?string $eventType = null,

        #[Assert\Length(max: WorkerRun::MAX_TRIGGER_FORGE_LENGTH)]
        public ?string $forge = null,

        #[Assert\Length(max: WorkerRun::MAX_TRIGGER_REPOSITORY_LENGTH)]
        public ?string $repository = null,

        #[Assert\Positive]
        public ?int $pullRequestNumber = null,

        #[Assert\Length(max: WorkerRun::MAX_TRIGGER_HEAD_SHA_LENGTH)]
        public ?string $headSha = null,

        #[Assert\Length(max: WorkerRun::MAX_TRIGGER_REASON_LENGTH)]
        public ?string $reason = null,
    ) {
    }

    public function trigger(): WorkerRunTrigger
    {
        return new WorkerRunTrigger(
            eventType: $this->eventType ?? throw new \LogicException('eventType is required after validation.'),
            forge: $this->forge,
            repository: $this->repository,
            pullRequestNumber: $this->pullRequestNumber,
            headSha: $this->headSha,
            reason: $this->reason,
        );
    }
}
