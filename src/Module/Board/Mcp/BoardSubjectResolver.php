<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
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
 * audit record a refusal leaves, which a project-scoped query cannot.
 *
 * A number is unique only inside a project, so it is looked up in the bound
 * project. The vote still runs on the result, so both paths share one check.
 */
final readonly class BoardSubjectResolver
{
    use ResolvesBoundProject;

    /**
     * The JSON schema of one relatedCards entry. It allows extra keys, so an
     * entry copied from card_get output passes with its number, title and status.
     */
    public const array RELATED_CARD_ITEM = [
        'type' => 'object',
        'properties' => [
            'cardId' => ['type' => 'string', 'description' => 'the id of a card of this project'],
            'kind' => ['type' => 'string', 'enum' => ['relates-to', 'blocks', 'blocked-by'], 'default' => 'relates-to', 'description' => 'how this card reads the other card'],
        ],
        'required' => ['cardId'],
    ];

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
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

    /** @param McpBoundProjectVoter::CARD_READ|McpBoundProjectVoter::CARD_WRITE $attribute */
    public function requireCardByIdOrNumber(?string $cardId, ?int $number, string $attribute): Card
    {
        if (null !== $cardId && null !== $number) {
            throw new ToolCallException('Pass cardId or number, not both.');
        }

        if (null !== $cardId) {
            return $this->requireCard($cardId, $attribute);
        }

        if (null === $number) {
            throw new ToolCallException('Pass cardId or number.');
        }

        if ($number < 1) {
            throw new ToolCallException(\sprintf('Card numbers count from 1, so %d is not a card number.', $number));
        }

        $card = $this->cards->findOneByProjectAndNumber($this->requireBoundProject($this->projectResolver), $number);

        if (null === $card) {
            throw new ToolCallException(\sprintf('This project has no card %d.', $number));
        }

        if (!$this->authorization->isGranted($attribute, $card)) {
            throw new ToolCallException(\sprintf('Card %d is not accessible.', $number));
        }

        return $card;
    }

    /** The column of the project's board with that slug. The refusal lists the slugs the board has. */
    public function requireColumn(Project $project, string $slug): BoardColumn
    {
        return $this->columnAmong($this->boardColumns->findForProject($project), $slug);
    }

    public function optionalColumn(Project $project, ?string $slug): ?BoardColumn
    {
        return null === $slug ? null : $this->requireColumn($project, $slug);
    }

    /**
     * The same lookup over columns the caller already read, so it costs no query.
     *
     * @param list<BoardColumn> $columns every column of one board
     */
    public function optionalColumnAmong(array $columns, ?string $slug): ?BoardColumn
    {
        return null === $slug ? null : $this->columnAmong($columns, $slug);
    }

    /** @param list<BoardColumn> $columns */
    private function columnAmong(array $columns, string $slug): BoardColumn
    {
        foreach ($columns as $column) {
            if ($column->slug === $slug) {
                return $column;
            }
        }

        throw new ToolCallException(\sprintf('Unknown status "%s". Use one of: %s.', $slug, implode(', ', array_map(static fn (BoardColumn $column): string => $column->slug, $columns))));
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

    /** Reads every value, so a filter reaches the reviewer cards the widget wrote. */
    public function requireReporter(string $reporter): CardReporter
    {
        return CardReporter::tryFrom($reporter)
            ?? throw new ToolCallException(\sprintf('Unknown reporter "%s". Use one of: %s.', $reporter, implode(', ', CardReporter::values())));
    }

    public function optionalReporter(?string $reporter): ?CardReporter
    {
        return null === $reporter ? null : $this->requireReporter($reporter);
    }

    /**
     * An agent may claim only the two reporters it can honestly claim.
     *
     * `reviewer` says the app could not name who raised the card, which is true
     * of the site-review widget and of nothing an MCP caller does. Leaving it to
     * the tool description would make the rule a request rather than a
     * constraint, and the value would be forgeable from any client.
     *
     * @var list<CardReporter>
     */
    private const array CLAIMABLE_REPORTERS = [CardReporter::Human, CardReporter::Agent];

    /** Narrower than requireReporter(): a write claims a reporter, a filter only matches one. */
    public function requireClaimedReporter(string $reporter): CardReporter
    {
        $parsed = CardReporter::tryFrom($reporter);
        if (null === $parsed || !\in_array($parsed, self::CLAIMABLE_REPORTERS, true)) {
            throw new ToolCallException(\sprintf('Unknown reporter "%s". Use one of: %s.', $reporter, implode(', ', array_map(static fn (CardReporter $r): string => $r->value, self::CLAIMABLE_REPORTERS))));
        }

        return $parsed;
    }

    public function optionalClaimedReporter(?string $reporter): ?CardReporter
    {
        return null === $reporter ? null : $this->requireClaimedReporter($reporter);
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<CardLinkInput>
     */
    public function requireRelatedCards(array $items): array
    {
        $parsed = [];
        foreach (array_values($items) as $index => $item) {
            if (!\is_array($item)) {
                throw new ToolCallException(\sprintf('relatedCards[%d] must be an object with a cardId.', $index));
            }

            $cardId = $item['cardId'] ?? null;
            if (!\is_string($cardId)) {
                throw new ToolCallException(\sprintf('relatedCards[%d].cardId must be a string.', $index));
            }

            $kind = $item['kind'] ?? CardLinkKind::RelatesTo->value;
            $parsed[] = new CardLinkInput(
                $cardId,
                (\is_string($kind) ? CardLinkKind::tryFrom($kind) : null)
                    ?? throw new ToolCallException(\sprintf('relatedCards[%d].kind: unknown kind "%s". Use one of: %s.', $index, \is_string($kind) ? $kind : get_debug_type($kind), implode(', ', array_map(static fn (CardLinkKind $case): string => $case->value, CardLinkKind::cases())))),
            );
        }

        return $parsed;
    }

    /**
     * @param array<mixed>|null $items
     *
     * @return list<CardLinkInput>|null
     */
    public function optionalRelatedCards(?array $items): ?array
    {
        return null === $items ? null : $this->requireRelatedCards($items);
    }

    public function requireSessionId(string $sessionId): Uuid
    {
        try {
            return Uuid::fromString($sessionId);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid sessionId. Pass the value of $CLAUDE_CODE_SESSION_ID.', $sessionId), previous: $e);
        }
    }

    private function parseId(string $id): Uuid
    {
        try {
            return Uuid::fromString($id);
        } catch (\InvalidArgumentException $e) {
            $hint = ctype_digit($id) ? ' To read a card by its number, pass number instead.' : '';

            throw new ToolCallException(\sprintf('"%s" is not a valid card ID.%s', $id, $hint), previous: $e);
        }
    }
}
