<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class MintApiTokenHandler
{
    /**
     * The only scope an account-level token can usefully carry. It reaches
     * `^/api/agent`, which is the surface the loupe CLI calls.
     *
     * An MCP token authenticates but resolves its project through
     * `projects.mcp_token_id`, which only the project mint route writes, so
     * every MCP tool call from an unbound token is refused. Minting one here
     * would hand the owner a credential that fails on first use.
     *
     * A site-review token fails the same way. Every endpoint under
     * `^/api/site-review` and `^/api/board/cards` resolves its project through
     * `projects.widget_token_id`, and only a project's own mint route writes
     * that binding. An account-level token binds to no project, so each of
     * those endpoints answers `token_not_bound_to_site`.
     */
    private const ApiTokenScope SCOPE = ApiTokenScope::Agent;

    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the owner exactly once
     */
    public function __invoke(MintApiTokenCommand $command): string
    {
        [$token, $raw] = ApiToken::issue($command->owner, $command->label, self::SCOPE);
        $this->em->persist($token);
        $this->em->flush();

        // Recorded after the commit, so no record can claim a token nobody holds.
        // No label: the owner types it, so it is their prose about their own
        // systems and has no place in a trail with no erasure path.
        $this->auditor->record(
            'account.api_token_minted',
            AuditOutcome::Success,
            [
                'userId' => null !== $command->owner->id ? (string) $command->owner->id : null,
                'tokenId' => (string) $token->id,
                'scope' => self::SCOPE->value,
            ],
            new AuditSubject('api_token', (string) $token->id),
            Auditor::CATEGORY_SECURITY,
        );

        return $raw;
    }
}
