<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Audit\AuditCredentialInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken implements AuditCredentialInterface
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

        /**
         * The last four characters of the raw token, so the UI can show which token
         * a project is using. Sixty of the sixty-four hex characters stay unknown,
         * but it is still part of the secret: keep it out of exports, logs and API
         * payloads. Null on rows issued before the column existed.
         */
        #[ORM\Column(length: 4, nullable: true)]
        public readonly ?string $tokenTail = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Whether a review submitted with this token may be forwarded to the owner's
     * agent. Off by default and only ever meaningful for a site-review widget
     * token, whose raw value is embedded in the page markup of the site it is
     * installed on — so anyone who can view that page holds the credential, and
     * opting in is a deliberate act by the owner rather than the default. The
     * widget is meant for staging and preview environments only, never a public
     * site, which is what bounds who that is. A collect-only token still accepts
     * comments and submits; only the Mercure nudge that reaches the agent is
     * withheld (see SubmitReviewHandler).
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $forwardsToAgent = false;

    /**
     * Set when the owner revokes this token. The row is kept (not deleted) so the
     * revocation log entry keeps resolving to a real token — a revoked token must
     * simply never authenticate again (see ApiTokenRepository::findOneByRawToken).
     */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    /** @return array{0: self, 1: non-empty-string} */
    public static function issue(User $owner, string $label, ApiTokenScope $scope): array
    {
        $raw = bin2hex(random_bytes(32));

        return [new self($owner, $label, $scope, hash('sha256', $raw), substr($raw, -4)), $raw];
    }

    public function matches(string $rawToken): bool
    {
        return hash_equals($this->tokenHash, hash('sha256', $rawToken));
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    #[\Override]
    public function auditIdentifier(): ?string
    {
        return $this->id?->toRfc4122();
    }
}
