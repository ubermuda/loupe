<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\GrantedCredentialRepository;
use App\Module\OAuth\Scope\ApiScope;
use App\Security\AuthenticatedCredential;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Ubermuda\AuditBundle\AuditActorContext;
use Ubermuda\AuditBundle\AuditActorInterface;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\AuditCredentialInterface;

/**
 * Reads the acting identity straight from TokenStorage, which nothing else in
 * src/ does — identity otherwise arrives in a command DTO. It cannot here: on
 * an MCP write the DTO carries a Project and the handler infers `project.owner`,
 * which is exactly the blindness an audit trail exists to remove. Only the
 * security token knows which credential acted.
 *
 * Detection is the last resort, under an explicit per-call channel and the
 * ambient AuditContext, and it only reports what the security token actually
 * says. Anything else is `system`: an unattributed record beats a confidently
 * mislabelled one, and a caller that knows better can declare it.
 */
final readonly class LoupeAuditActorProvider implements AuditActorProviderInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private GrantedCredentialRepository $grantedCredentials,
        private AuditContext $auditContext,
    ) {
    }

    #[\Override]
    public function currentActor(): AuditActorContext
    {
        $securityToken = $this->tokenStorage->getToken();
        $credential = AuthenticatedCredential::of($securityToken);
        $user = $securityToken?->getUser();

        $channel = $this->auditContext->channel ?? match (true) {
            null !== $credential => $this->channelOf($credential),
            null !== $securityToken => AuditChannel::Session,
            default => AuditChannel::System,
        };

        $actor = $user instanceof AuditActorInterface ? $user : null;

        // Named rather than anonymous: only the account being erased loses its
        // actor, so an admin who deletes somebody else keeps theirs.
        $erasedActorId = $this->auditContext->erasedActorId;

        if (null !== $erasedActorId && null !== $actor && $actor->auditIdentifier() === $erasedActorId) {
            $actor = null;
        }

        return new AuditActorContext(
            $actor,
            $user instanceof User && null !== $credential ? $this->recordOf($credential, $user) : null,
            $channel->value,
            $this->auditContext->ambientContext,
        );
    }

    public function currentChannel(): AuditChannel
    {
        return AuditChannel::from($this->currentActor()->channel);
    }

    /**
     * One credential reaches several firewalls, so the narrowest surface it
     * carries names the channel. A widget grant is the narrowest of the three.
     */
    private function channelOf(AuthenticatedCredential $credential): AuditChannel
    {
        return match (true) {
            $credential->hasRole(ApiScope::SiteReview->role()) => AuditChannel::Widget,
            $credential->hasRole(ApiScope::Mcp->role()) => AuditChannel::Mcp,
            $credential->hasRole(ApiScope::Agent->role()) => AuditChannel::Agent,
            default => AuditChannel::Session,
        };
    }

    private function recordOf(AuthenticatedCredential $credential, User $owner): AuditCredentialInterface
    {
        return $this->grantedCredentials->findOrCreate($credential->id, $owner);
    }
}
