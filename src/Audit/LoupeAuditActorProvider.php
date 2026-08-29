<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use App\Module\Audit\AuditActorContext;
use App\Module\Audit\AuditActorInterface;
use App\Module\Audit\AuditActorProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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
        private AuthenticatedApiTokenResolver $apiTokens,
        private AuditContext $auditContext,
    ) {
    }

    #[\Override]
    public function currentActor(): AuditActorContext
    {
        $securityToken = $this->tokenStorage->getToken();
        $apiToken = $this->apiTokens->forSecurityToken($securityToken);
        $user = $securityToken?->getUser();

        $channel = $this->auditContext->channel ?? match (true) {
            null !== $apiToken => match ($apiToken->scope) {
                ApiTokenScope::Mcp => AuditChannel::Mcp,
                ApiTokenScope::SiteReview => AuditChannel::Widget,
            },
            null !== $securityToken => AuditChannel::Session,
            default => AuditChannel::System,
        };

        return new AuditActorContext(
            $user instanceof AuditActorInterface ? $user : null,
            // The display name rather than the email: a record already carries
            // the actor id for identity, and the label exists to be read.
            $user instanceof User ? $user->fullName : null,
            $apiToken,
            $channel->value,
            $this->auditContext->ambientContext,
        );
    }

    public function currentChannel(): AuditChannel
    {
        return AuditChannel::from($this->currentActor()->channel);
    }
}
