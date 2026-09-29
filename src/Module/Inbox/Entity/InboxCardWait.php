<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One reason a card waits for a person. A document wait names one version, and a run wait names one run. */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_card_waits')]
class InboxCardWait
{
    public const int MAX_REASON_LENGTH = 200;

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

        #[ORM\Column(length: self::MAX_REASON_LENGTH)]
        public string $reason,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $documentId = null,

        #[ORM\Column(nullable: true)]
        public readonly ?int $versionNumber = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $runId = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt = new \DateTimeImmutable(),
    ) {
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A wait reason is at most %d characters.', self::MAX_REASON_LENGTH));
        }
        self::computeKey($trigger, $documentId, $versionNumber, $runId);
    }

    /** Two waits with the same key are the same wait. */
    public function key(): string
    {
        return self::computeKey($this->trigger, $this->documentId, $this->versionNumber, $this->runId);
    }

    public static function computeKey(InboxCardWaitTrigger $trigger, ?Uuid $documentId = null, ?int $versionNumber = null, ?Uuid $runId = null): string
    {
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
