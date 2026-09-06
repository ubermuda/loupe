<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One pull request link on a card.
 *
 * $repository and $number are nullable because a URL no parser recognises still
 * belongs on the card: the link is kept as given, and only the parts a parser
 * could read are stored beside it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'board_card_pull_requests')]
class CardPullRequest
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: Card::class, inversedBy: 'pullRequests')]
        public readonly Card $card,

        #[ORM\Column(length: 512)]
        public string $url,

        #[ORM\Column(length: 20, enumType: Forge::class)]
        public Forge $forge = Forge::Other,

        /** The `owner/repo` pair, when the forge's URL shape carries one. */
        #[ORM\Column(length: 255, nullable: true)]
        public ?string $repository = null,

        #[ORM\Column(nullable: true)]
        public ?int $number = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $addedAt = new \DateTimeImmutable(),
    ) {
    }
}
