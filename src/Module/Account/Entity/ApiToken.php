<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    private function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $owner,

        #[ORM\Column(length: 100)]
        public string $label,

        #[ORM\Column(enumType: ApiTokenScope::class)]
        public readonly ApiTokenScope $scope,

        #[ORM\Column(length: 64, unique: true)]
        public readonly string $tokenHash,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /** @return array{0: self, 1: non-empty-string} */
    public static function issue(User $owner, string $label, ApiTokenScope $scope): array
    {
        $raw = bin2hex(random_bytes(32));

        return [new self($owner, $label, $scope, hash('sha256', $raw)), $raw];
    }

    public function matches(string $rawToken): bool
    {
        return hash_equals($this->tokenHash, hash('sha256', $rawToken));
    }
}
