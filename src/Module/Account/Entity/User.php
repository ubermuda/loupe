<?php

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AdminBundle\Security\AdminPromotableUser;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, AdminPromotableUser
{
    /**
     * The one account that authors everything an agent writes, so a machine
     * reply is never attributed to the human whose token carried it. Inserted
     * by a migration with this literal id, which is why it is a constant rather
     * than a lookup by email: every query that must exclude it can do so
     * without loading a row.
     */
    public const string AGENT_ID = '1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9';

    /** The users.full_name column width, and the single source of truth for every check against it. */
    public const int MAX_FULL_NAME_LENGTH = 150;

    /** The users.suspended_reason column width, and the single source of truth for every check against it. */
    public const int MAX_SUSPENDED_REASON_LENGTH = 500;

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

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $wizardCompletedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastSignedInAt = null;

    /**
     * A disabled account keeps its data and may still log in, but is excluded
     * from the registration-cap count and blocked by the billing paywall until
     * a subscription re-enables it. Set by the trial-end sweep and the Stripe
     * webhook; cleared only when a subscription activates.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $disabledAt = null;

    /**
     * Set by an admin, and distinct from disabledAt, which billing owns: a
     * suspension must survive the trial sweep that clears disabledAt.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(length: self::MAX_SUSPENDED_REASON_LENGTH, nullable: true)]
    public ?string $suspendedReason = null;

    /** SET NULL, not RESTRICT: AccountPurger removes the row with raw SQL. */
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?self $suspendedBy = null;

    /**
     * Null on every row predating the terms gate, and on every account the
     * OAuth path creates — that path has no form to ask on, so the null is what
     * makes RequireTermsAcceptanceListener divert the user to the interstitial.
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $termsAcceptedAt = null;

    /** The terms revision agreed to; a bump of app.terms.version re-prompts everyone. */
    #[ORM\Column(length: 32, nullable: true)]
    public ?string $termsVersion = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailVerificationTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $passwordResetTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $accountDeletionTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accountDeletionTokenExpiresAt = null;

    public function __construct(
        /**
         * Every account has one, so it is always safe to render. The forms ask
         * for it; the paths that cannot ask — social login with a nameless
         * provider, the console admin command — derive it from the email with
         * DisplayNameDeriver.
         */
        #[ORM\Column(length: self::MAX_FULL_NAME_LENGTH)]
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

        // Nullable: accounts created through social login have no local password.
        #[ORM\Column(nullable: true)]
        public ?string $password = null,

        #[ORM\Column]
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * False for accounts that only ever signed in through a social provider.
     * Such an account cannot be logged into with the password form, and linking
     * a social identity to it needs no password confirmation.
     */
    public function hasUsablePassword(): bool
    {
        return null !== $this->password;
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

    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    public function isAgent(): bool
    {
        return self::AGENT_ID === (string) $this->id;
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

    public function hasEmailVerificationToken(): bool
    {
        return null !== $this->emailVerificationTokenHash;
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

    // -------------------------------------------------------------------------
    // Account deletion token
    // -------------------------------------------------------------------------

    public function generateAccountDeletionToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->accountDeletionTokenHash = hash('sha256', $token);
        $this->accountDeletionTokenExpiresAt = new \DateTimeImmutable('+1 hour');

        return $token;
    }

    public function hasActiveAccountDeletionToken(): bool
    {
        return null !== $this->accountDeletionTokenHash
            && null !== $this->accountDeletionTokenExpiresAt
            && $this->accountDeletionTokenExpiresAt > new \DateTimeImmutable();
    }

    public function isAccountDeletionTokenValid(string $token): bool
    {
        $hash = $this->accountDeletionTokenHash;
        $expiresAt = $this->accountDeletionTokenExpiresAt;

        if (null === $hash || null === $expiresAt) {
            return false;
        }

        if ($expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    public function clearAccountDeletionToken(): void
    {
        $this->accountDeletionTokenHash = null;
        $this->accountDeletionTokenExpiresAt = null;
    }
}
