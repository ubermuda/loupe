<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Entity;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SiteRepository::class)]
#[ORM\Table(name: 'site_review_sites')]
#[ORM\UniqueConstraint(name: 'uniq_site_review_site_owner_name', columns: ['owner_id', 'name'])]
class Site
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /**
     * The widget token bound to this site. Nullable: a site without a token
     * simply cannot receive comments until one is minted. Revoking the token
     * (Account UI) nulls this via ON DELETE SET NULL.
     */
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\OneToOne(targetEntity: ApiToken::class)]
    public ?ApiToken $token = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 100)]
        public string $name,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
