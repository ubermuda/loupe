<?php

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $roles = [];

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailVerificationTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $passwordResetTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

    public function __construct(
        #[ORM\Column(length: 30, unique: true)]
        public string $username,

        #[ORM\Column(length: 150)]
        public string $fullName,

        /** @phpstan-var non-empty-string */
        #[ORM\Column(length: 180, unique: true)]
        public string $email {
            set(string $email) {
                if ('' === $email) {
                    throw new \InvalidArgumentException('Email must not be empty.');
                }
                $this->email = strtolower($email);
            }
        },

        #[ORM\Column(nullable: false)]
        public ?string $password = null,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function isVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    // -------------------------------------------------------------------------
    // Email verification token
    // -------------------------------------------------------------------------

    public function generateEmailVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->emailVerificationTokenHash = hash('sha256', $token);
        $this->emailVerificationTokenExpiresAt = new \DateTimeImmutable('+1 hour');

        return $token;
    }

    public function isEmailVerificationTokenValid(string $token): bool
    {
        $hash = $this->emailVerificationTokenHash;
        $expiresAt = $this->emailVerificationTokenExpiresAt;

        if (null === $hash || null === $expiresAt) {
            return false;
        }

        if ($expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    public function clearEmailVerificationToken(): void
    {
        $this->emailVerificationTokenHash = null;
        $this->emailVerificationTokenExpiresAt = null;
    }

    // -------------------------------------------------------------------------
    // Password reset token
    // -------------------------------------------------------------------------

    public function generatePasswordResetToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->passwordResetTokenHash = hash('sha256', $token);
        $this->passwordResetTokenExpiresAt = new \DateTimeImmutable('+1 hour');

        return $token;
    }

    public function hasActivePasswordResetToken(): bool
    {
        return null !== $this->passwordResetTokenHash
            && null !== $this->passwordResetTokenExpiresAt
            && $this->passwordResetTokenExpiresAt > new \DateTimeImmutable();
    }

    public function isPasswordResetTokenValid(string $token): bool
    {
        $hash = $this->passwordResetTokenHash;
        $expiresAt = $this->passwordResetTokenExpiresAt;

        if (null === $hash || null === $expiresAt) {
            return false;
        }

        if ($expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    public function clearPasswordResetToken(): void
    {
        $this->passwordResetTokenHash = null;
        $this->passwordResetTokenExpiresAt = null;
    }
}
