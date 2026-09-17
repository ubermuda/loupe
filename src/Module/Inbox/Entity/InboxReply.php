<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Repository\InboxReplyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: InboxReplyRepository::class)]
#[ORM\Index(columns: ['item_id', 'created_at', 'id'])]
#[ORM\Table(name: 'inbox_replies')]
#[ORM\UniqueConstraint(columns: ['item_id', 'submission_id'])]
class InboxReply
{
    public const int MAX_BODY_LENGTH = 2000;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $author,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $body,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $submissionId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
