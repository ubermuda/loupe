<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\AskInboxRunCommand;
use App\Module\Inbox\Command\AskInboxSuggestion;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\RunSuggestions;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the items and the arguments an inbox tool call may act on.
 *
 * An item is looked up by id alone and scoped by McpBoundProjectVoter, so a
 * token bound to one project of a user cannot reach the items of another, and a
 * refusal leaves an audit record.
 */
final readonly class InboxSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private InboxItemRepository $inboxItems,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function requireProject(): Project
    {
        return $this->requireBoundProject($this->projectResolver);
    }

    /** @param McpBoundProjectVoter::INBOX_ITEM_READ|McpBoundProjectVoter::INBOX_ITEM_WRITE $attribute */
    public function requireItem(string $itemId, string $attribute): InboxItem
    {
        $this->requireBoundProject($this->projectResolver);

        $item = $this->inboxItems->find($this->requireUuid($itemId, 'item ID'));

        if (null === $item || !$this->authorization->isGranted($attribute, $item)) {
            // The same message for "does not exist" and "belongs to another
            // project", so a tool cannot probe what exists outside the project.
            throw new ToolCallException(\sprintf('Inbox item "%s" not found or not accessible.', $itemId));
        }

        return $item;
    }

    public function requireUuid(string $value, string $label): Uuid
    {
        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException(\sprintf('"%s" is not a valid %s.', $value, $label), previous: $e);
        }
    }

    public function optionalUuid(?string $value, string $label): ?Uuid
    {
        return null === $value ? null : $this->requireUuid($value, $label);
    }

    public function optionalState(?string $state): ?InboxItemState
    {
        if (null === $state) {
            return null;
        }

        return InboxItemState::tryFrom($state)
            ?? throw new ToolCallException(\sprintf('Unknown state "%s". Use one of: %s.', $state, implode(', ', array_map(static fn (InboxItemState $case): string => $case->value, InboxItemState::cases()))));
    }

    /**
     * Checks the shape of each item the agent sent. The schema describes the
     * shape, and a client is free to ignore it, so this is the check.
     *
     * @param array<mixed> $items
     *
     * @return list<AskInboxItem>
     */
    public function requireAskItems(array $items): array
    {
        $parsed = [];
        foreach (array_values($items) as $index => $item) {
            if (!\is_array($item)) {
                throw new ToolCallException(\sprintf('items[%d] must be an object.', $index));
            }

            $kind = $this->string($item, 'kind', $index);
            // Only Loupe opens a wait, notice or workflow item, so an agent never names those kinds.
            $agentKinds = array_values(array_filter(InboxItemKind::cases(), static fn (InboxItemKind $case): bool => !\in_array($case, [InboxItemKind::Wait, InboxItemKind::Notice, InboxItemKind::Workflow], true)));
            $parsed[] = new AskInboxItem(
                kind: array_find($agentKinds, static fn (InboxItemKind $case): bool => $case->value === $kind)
                    ?? throw new ToolCallException(\sprintf('items[%d].kind: unknown kind "%s". Use one of: %s.', $index, $kind, implode(', ', array_map(static fn (InboxItemKind $case): string => $case->value, $agentKinds)))),
                title: $this->string($item, 'title', $index),
                body: null === ($item['body'] ?? null) ? null : $this->string($item, 'body', $index),
                options: $this->strings($item, 'options', $index),
                multiple: $this->bool($item, 'multiple', $index) ?? false,
                freeText: $this->bool($item, 'freeText', $index) ?? false,
                blocking: $this->bool($item, 'blocking', $index),
                cardIds: $this->strings($item, 'cardIds', $index),
                documentIds: $this->strings($item, 'documentIds', $index),
                reviewDocumentId: null === ($item['reviewDocumentId'] ?? null) ? null : $this->string($item, 'reviewDocumentId', $index),
                reviewPullRequestId: null === ($item['reviewPullRequestId'] ?? null) ? null : $this->string($item, 'reviewPullRequestId', $index),
                suggestedActions: $this->suggestions($item, $index),
            );
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $item
     *
     * @return list<AskInboxSuggestion>
     */
    private function suggestions(array $item, int $index): array
    {
        $value = $item['suggestedActions'] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            throw new ToolCallException(\sprintf('items[%d].suggestedActions must be a list of objects.', $index));
        }

        $suggestions = [];
        foreach ($value as $position => $entry) {
            if (!\is_array($entry)) {
                throw new ToolCallException(\sprintf('items[%d].suggestedActions[%d] must be an object.', $index, $position));
            }
            $action = $entry['action'] ?? null;
            $reason = $entry['reason'] ?? null;
            $recommended = $entry['recommended'] ?? false;
            $cardId = $entry['cardId'] ?? null;
            if (!\is_string($action) || !\is_string($reason)) {
                throw new ToolCallException(\sprintf('items[%d].suggestedActions[%d] needs a string action and a string reason.', $index, $position));
            }
            if (!\is_bool($recommended) || (null !== $cardId && !\is_string($cardId))) {
                throw new ToolCallException(\sprintf('items[%d].suggestedActions[%d]: recommended must be true or false, and cardId a string.', $index, $position));
            }
            $suggestions[] = new AskInboxSuggestion($action, $reason, $recommended, $cardId, $this->runCommand($entry, $index, $position), $this->proposedEntry($entry, $index, $position), $this->prompt($entry, $index, $position));
        }

        return $suggestions;
    }

    /** @param array<mixed> $suggestion */
    private function runCommand(array $suggestion, int $index, int $position): ?AskInboxRunCommand
    {
        $where = \sprintf('items[%d].suggestedActions[%d]', $index, $position);
        $value = $suggestion['command'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new ToolCallException(\sprintf('%s.command must be an object with an entry, and optionally a branch and params.', $where));
        }
        $entry = $value['entry'] ?? null;
        $branch = $value['branch'] ?? RunSuggestions::DEFAULT_BRANCH;
        $params = $value['params'] ?? [];
        if (!\is_string($entry) || !\is_string($branch)) {
            throw new ToolCallException(\sprintf('%s.command needs a string entry, and branch must be a string.', $where));
        }
        if (!\is_array($params) || ([] !== $params && array_is_list($params))) {
            throw new ToolCallException(\sprintf('%s.command.params must be an object that maps each param name to a string.', $where));
        }
        $strings = [];
        foreach ($params as $name => $param) {
            if (!\is_string($param)) {
                throw new ToolCallException(\sprintf('%s.command.params.%s must be a string.', $where, $name));
            }
            $strings[(string) $name] = $param;
        }

        return new AskInboxRunCommand($entry, $branch, $strings);
    }

    /** @param array<mixed> $suggestion */
    private function proposedEntry(array $suggestion, int $index, int $position): ?string
    {
        $value = $suggestion['proposedEntry'] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new ToolCallException(\sprintf('items[%d].suggestedActions[%d].proposedEntry must be a string of YAML.', $index, $position));
        }

        return $value;
    }

    /** @param array<mixed> $suggestion */
    private function prompt(array $suggestion, int $index, int $position): ?string
    {
        $value = $suggestion['prompt'] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw new ToolCallException(\sprintf('items[%d].suggestedActions[%d].prompt must be a string.', $index, $position));
        }

        return $value;
    }

    /** @param array<mixed> $item */
    private function string(array $item, string $key, int $index): string
    {
        $value = $item[$key] ?? null;
        if (!\is_string($value)) {
            throw new ToolCallException(\sprintf('items[%d].%s must be a string.', $index, $key));
        }

        return $value;
    }

    /** @param array<mixed> $item */
    private function bool(array $item, string $key, int $index): ?bool
    {
        $value = $item[$key] ?? null;
        if (null !== $value && !\is_bool($value)) {
            throw new ToolCallException(\sprintf('items[%d].%s must be true or false.', $index, $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $item
     *
     * @return list<string>
     */
    private function strings(array $item, string $key, int $index): array
    {
        $value = $item[$key] ?? [];
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $entry): bool => !\is_string($entry))) {
            throw new ToolCallException(\sprintf('items[%d].%s must be a list of strings.', $index, $key));
        }

        /* @var list<string> $value */
        return $value;
    }
}
