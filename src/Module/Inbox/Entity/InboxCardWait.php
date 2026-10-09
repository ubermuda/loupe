<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One reason a card waits for a person. A document wait names one version, a run wait names one run, and a pull request wait names one head commit. */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_card_waits')]
class InboxCardWait
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(length: 30, nullable: true, enumType: InboxCardWaitEndReason::class)]
    public ?InboxCardWaitEndReason $endReason = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxCardWatch::class, inversedBy: 'waits')]
        public readonly InboxCardWatch $watch,

        #[ORM\Column(length: 30, enumType: InboxCardWaitTrigger::class)]
        public readonly InboxCardWaitTrigger $trigger,

        #[ORM\Column(length: 20, enumType: InboxCardWaitType::class)]
        public readonly InboxCardWaitType $type,

        #[ORM\Column(length: 200, enumType: InboxCardWaitReason::class)]
        public InboxCardWaitReason $reason,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $documentId = null,

        #[ORM\Column(nullable: true)]
        public readonly ?int $versionNumber = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $runId = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $pullRequestId = null,

        #[ORM\Column(length: 64, nullable: true)]
        public readonly ?string $headSha = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt = new \DateTimeImmutable(),

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $pauseId = null,
    ) {
        self::computeKey($trigger, $documentId, $versionNumber, $runId, $pullRequestId, $headSha, $pauseId);
    }

    /** Two waits with the same key are the same wait. */
    public function key(): string
    {
        return self::computeKey($this->trigger, $this->documentId, $this->versionNumber, $this->runId, $this->pullRequestId, $this->headSha, $this->pauseId);
    }

    public static function computeKey(InboxCardWaitTrigger $trigger, ?Uuid $documentId = null, ?int $versionNumber = null, ?Uuid $runId = null, ?Uuid $pullRequestId = null, ?string $headSha = null, ?Uuid $pauseId = null): string
    {
        if ($trigger->isPause()) {
            if (null === $pauseId || null !== $documentId || null !== $versionNumber || null !== $runId || null !== $pullRequestId || null !== $headSha) {
                throw new \InvalidArgumentException('A pause wait names a pause, and nothing else.');
            }

            return \sprintf('%s:%s', $trigger->value, $pauseId->toRfc4122());
        }

        if (null !== $pauseId) {
            throw new \InvalidArgumentException('Only a pause wait names a pause.');
        }

        if ($trigger->isPullRequest()) {
            if (null === $pullRequestId || null === $headSha || '' === $headSha || null !== $documentId || null !== $versionNumber || null !== $runId) {
                throw new \InvalidArgumentException('A pull request wait names a pull request and a head commit, and no document or run.');
            }

            return \sprintf('%s:%s:%s', $trigger->value, $pullRequestId->toRfc4122(), $headSha);
        }

        if (null !== $pullRequestId || null !== $headSha) {
            throw new \InvalidArgumentException('Only a pull request wait names a pull request.');
        }

        if ($trigger->isDocument()) {
            if (null === $documentId || null === $versionNumber || null !== $runId) {
                throw new \InvalidArgumentException('A document wait names a document and a version, and no run.');
            }

            return \sprintf('%s:%s:%d', $trigger->value, $documentId->toRfc4122(), $versionNumber);
        }

        if (null === $runId || null !== $documentId || null !== $versionNumber) {
            throw new \InvalidArgumentException('A run wait names a run, and no document.');
        }

        return \sprintf('%s:%s', $trigger->value, $runId->toRfc4122());
    }
}
