<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the cards and the enum arguments an MCP tool call may act on.
 *
 * A card is looked up by id alone and scoped by McpBoundProjectVoter, which is
 * a different question from the one CardVoter answers: the token authenticates
 * as the project owner, so an ownership check alone would let a token bound to
 * one of a user's projects reach cards in another. The voter also writes the
 * audit record a refusal leaves, which a project-scoped query cannot, because
 * it returns nothing and the vote never runs.
 */
final readonly class BoardSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private CardRepository $cards,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function requireProject(): Project
    {
        return $this->requireBoundProject($this->projectResolver);
    }

    /** @param McpBoundProjectVoter::CARD_READ|McpBoundProjectVoter::CARD_WRITE $attribute */
    public function requireCard(string $cardId, string $attribute): Card
    {
        // An unbound token is a setup mistake with its own fix, so it is
        // reported before the scope check turns it into "not accessible".
        $this->requireBoundProject($this->projectResolver);

        $card = $this->cards->find($this->parseId($cardId));

        if (null === $card || !$this->authorization->isGranted($attribute, $card)) {
            // Deliberately identical for "does not exist" and "belongs to
            // another project", so a tool cannot probe what exists outside the
            // token's project.
            throw new ToolCallException(\sprintf('Card "%s" not found or not accessible.', $cardId));
        }

        return $card;
    }

    public function requireStatus(string $status): CardStatus
    {
        return CardStatus::tryFrom($status)
            ?? throw new ToolCallException(\sprintf('Unknown status "%s". Use one of: %s.', $status, implode(', ', CardStatus::values())));
    }

    public function optionalStatus(?string $status): ?CardStatus
    {
        return null === $status ? null : $this->requireStatus($status);
    }

    public function requireType(string $type): CardType
    {
        return CardType::tryFrom($type)
            ?? throw new ToolCallException(\sprintf('Unknown type "%s". Use one of: %s.', $type, implode(', ', CardType::values())));
    }

    public function optionalType(?string $type): ?CardType
    {
        return null === $type ? null : $this->requireType($type);
    }

    /** Named rather than numbered: the backing integers exist for the board's ORDER BY. */
    public function requirePriority(string $priority): CardPriority
    {
        return CardPriority::fromName($priority)
            ?? throw new ToolCallException(\sprintf('Unknown priority "%s". Use one of: %s.', $priority, implode(', ', CardPriority::names())));
    }

    public function optionalPriority(?string $priority): ?CardPriority
    {
        return null === $priority ? null : $this->requirePriority($priority);
    }

    public function requireOrigin(string $origin): CardOrigin
    {
        return CardOrigin::tryFrom($origin)
            ?? throw new ToolCallException(\sprintf('Unknown origin "%s". Use one of: %s.', $origin, implode(', ', CardOrigin::values())));
    }

    public function optionalOrigin(?string $origin): ?CardOrigin
    {
        return null === $origin ? null : $this->requireOrigin($origin);
    }

    private function parseId(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid card ID.', $id), previous: $e);
        }
    }
}
