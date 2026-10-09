<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The GitHub account a person connected to their Loupe account. Loupe acts as
 * that person on GitHub with these tokens. A refresh token works once, so a
 * refresh must store the new pair before anything else uses the row.
 */
#[ORM\Entity(repositoryClass: GitHubUserConnectionRepository::class)]
#[ORM\Table(name: 'github_user_connections')]
class GitHubUserConnection
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** Set when GitHub refused the refresh token for good. The person must connect again. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiredAt = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\Column(type: Types::BIGINT)]
        public int $githubUserId,

        #[ORM\Column(length: 255)]
        public string $login,

        #[ORM\Column(type: 'encrypted_string')]
        public string $accessToken,

        #[ORM\Column(type: 'encrypted_string')]
        public string $refreshToken,

        #[ORM\Column]
        public \DateTimeImmutable $accessTokenExpiresAt,

        #[ORM\Column]
        public \DateTimeImmutable $refreshTokenExpiresAt,

        #[ORM\Column]
        public \DateTimeImmutable $connectedAt = new \DateTimeImmutable(),
    ) {
    }
}
