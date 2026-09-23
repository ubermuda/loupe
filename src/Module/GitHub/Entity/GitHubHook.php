<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The webhook a person adds in a repository's settings. The key is public in
 * the delivery URL, and the secret signs every delivery.
 */
#[ORM\Entity(repositoryClass: GitHubHookRepository::class)]
#[ORM\Table(name: 'github_hooks')]
class GitHubHook
{
    public const string KEY_PATTERN = '[a-z2-7]{26}';

    private const string KEY_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';
    private const int KEY_LENGTH = 26;
    private const int SECRET_BYTES = 32;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastAcceptedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastRefusedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $lastRefusedReason = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(length: self::KEY_LENGTH, unique: true)]
        public readonly string $hookKey,

        #[ORM\Column(type: 'encrypted_string')]
        public string $secret,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public static function newKey(): string
    {
        $key = '';
        for ($i = 0; $i < self::KEY_LENGTH; ++$i) {
            $key .= self::KEY_ALPHABET[random_int(0, \strlen(self::KEY_ALPHABET) - 1)];
        }

        return $key;
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    public function accepted(\DateTimeImmutable $at): void
    {
        $this->lastAcceptedAt = $at;
    }

    public function refused(\DateTimeImmutable $at, string $reason): void
    {
        $this->lastRefusedAt = $at;
        $this->lastRefusedReason = $reason;
    }

    public function health(): GitHubHookHealth
    {
        if (null !== $this->lastRefusedAt && (null === $this->lastAcceptedAt || $this->lastRefusedAt > $this->lastAcceptedAt)) {
            return GitHubHookHealth::Failing;
        }

        return null === $this->lastAcceptedAt ? GitHubHookHealth::Waiting : GitHubHookHealth::Working;
    }
}
