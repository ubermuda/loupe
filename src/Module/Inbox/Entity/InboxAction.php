<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Repository\InboxActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One click of the owner on an action of an inbox item. The row holds ids only. The status of the
 * action is read from the bridge command, the work request or the pause that it started.
 */
#[ORM\Entity(repositoryClass: InboxActionRepository::class)]
#[ORM\Index(name: 'inbox_actions_bridge_command_idx', columns: ['bridge_command_id'])]
#[ORM\Index(name: 'inbox_actions_work_request_idx', columns: ['work_request_id'])]
#[ORM\Table(name: 'inbox_actions')]
class InboxAction
{
    public const int MAX_NOTE_LENGTH = 2000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $bridgeCommandId = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $workRequestId = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $pauseId = null;

    /** The wait the action answers. Any other open wait brings the reply back. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $waitId = null;

    /** The prompt of a started session, as the owner confirmed it. Null when the session had none. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $sessionPrompt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column(length: 20, enumType: InboxActionKind::class)]
        public readonly InboxActionKind $kind,

        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly ?User $requestedBy,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $note = '',

        #[ORM\Column]
        public readonly \DateTimeImmutable $requestedAt = new \DateTimeImmutable(),
    ) {
    }
}
