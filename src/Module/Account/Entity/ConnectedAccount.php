<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\ConnectedAccountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A social identity linked to a local account. The link is keyed on the
 * provider's immutable user id, never on the email — a provider email can
 * change hands, the subject id cannot.
 */
#[ORM\Entity(repositoryClass: ConnectedAccountRepository::class)]
#[ORM\Table(name: 'connected_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_provider_subject', columns: ['provider', 'provider_user_id'])]
class ConnectedAccount
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public User $user,

        #[ORM\Column(length: 20, enumType: SocialProvider::class)]
        public SocialProvider $provider,

        #[ORM\Column(name: 'provider_user_id', length: 191)]
        public string $providerUserId,

        /**
         * The raw email the provider reported at link time, kept for reference
         * and support only. It is never used to match or authenticate — see
         * ResolveSocialLoginHandler.
         */
        #[ORM\Column(length: 180, nullable: true)]
        public ?string $email = null,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }
}
