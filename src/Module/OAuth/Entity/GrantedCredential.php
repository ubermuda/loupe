<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\GrantedCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditCredentialInterface;

/**
 * The durable record of one machine credential, so an audit entry keeps naming
 * what acted. An access token cannot play this part: its identifier changes at
 * every refresh and the purge task removes it once it expires.
 *
 * The handle is the same value the rate limiters key on, which stays the same
 * for the life of a grant.
 */
#[ORM\Entity(repositoryClass: GrantedCredentialRepository::class)]
#[ORM\Table(name: 'oauth_credentials')]
class GrantedCredential implements AuditCredentialInterface
{
    public const int MAX_HANDLE_LENGTH = 255;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\Column(length: self::MAX_HANDLE_LENGTH, unique: true)]
        public readonly string $handle,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    #[\Override]
    public function auditIdentifier(): ?string
    {
        return $this->id?->toRfc4122();
    }
}
