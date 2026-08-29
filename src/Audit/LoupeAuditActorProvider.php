<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use App\Module\Audit\AuditActorContext;
use App\Module\Audit\AuditActorInterface;
use App\Module\Audit\AuditActorProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Reads the acting identity straight from TokenStorage, which nothing else in
 * src/ does — identity otherwise arrives in a command DTO. It cannot here: on
 * an MCP write the DTO carries a Project and the handler infers `project.owner`,
 * which is exactly the blindness an audit trail exists to remove. Only the
 * security token knows which credential acted.
 */
final readonly class LoupeAuditActorProvider implements AuditActorProviderInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private AuthenticatedApiTokenResolver $apiTokens,
        private RequestStack $requestStack,
        private AuditContext $auditContext,
    ) {
    }

    #[\Override]
    public function currentActor(): AuditActorContext
    {
        $securityToken = $this->tokenStorage->getToken();
        $apiToken = $this->apiTokens->forSecurityToken($securityToken);
        $user = $securityToken?->getUser();

        return new AuditActorContext(
            $user instanceof AuditActorInterface ? $user : null,
            $apiToken,
            $this->channelFor($securityToken, $apiToken)->value,
            $this->auditContext->ambientContext,
        );
    }

    public function currentChannel(): AuditChannel
    {
        $securityToken = $this->tokenStorage->getToken();

        return $this->channelFor($securityToken, $this->apiTokens->forSecurityToken($securityToken));
    }

    private function channelFor(?TokenInterface $securityToken, ?ApiToken $apiToken): AuditChannel
    {
        if (null !== $this->auditContext->channel) {
            return $this->auditContext->channel;
        }

        if (null !== $apiToken) {
            return match ($apiToken->scope) {
                ApiTokenScope::Mcp => AuditChannel::Mcp,
                ApiTokenScope::SiteReview => AuditChannel::Widget,
            };
        }

        if (null !== $securityToken) {
            return AuditChannel::Session;
        }

        // An unauthenticated request is Stripe's webhook, the only anonymous
        // endpoint that writes; the controller verifies its signature.
        return null === $this->requestStack->getCurrentRequest() ? AuditChannel::System : AuditChannel::Webhook;
    }
}
