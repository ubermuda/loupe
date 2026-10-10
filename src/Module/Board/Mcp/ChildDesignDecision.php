<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\ChildDesignChoices;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;

/** Checks the choice an agent states for a card it files under a parent, before the card tools write anything. */
final readonly class ChildDesignDecision
{
    public function __construct(
        private BoardSubjectResolver $subjects,
        private ChildDesignChoices $choices,
    ) {
    }

    /**
     * Null when the call carries no choice to run: the card write goes ahead as it does with no choice.
     *
     * @param ?string      $parentCardId the `parentCardId` argument of the call, with '' for clearing the parent
     * @param ?Card        $current      the card that a `card_update` changes, null for `card_create`
     * @param list<string> $documentIds  the documents the call links
     *
     * @throws ToolCallException when the choice is wrong, or missing where the owner would get the question
     */
    public function resolve(?string $parentCardId, ?Card $current, ?string $childDesign, array $documentIds, bool $statusGiven): ?string
    {
        if (null !== $childDesign && !\in_array($childDesign, ChildDesignChoices::CHOICES, true)) {
            throw new ToolCallException('childDesign: Pass "inherit" or "own".');
        }
        $parentSet = null !== $parentCardId && '' !== $parentCardId;
        $parent = $parentSet ? $this->parent($parentCardId) : (null === $parentCardId ? $current?->parent : null);
        if ($parentSet && null === $parent) {
            // The card handler reports the parent that does not resolve.
            return null;
        }
        if (null === $parent) {
            return null === $childDesign ? null : throw new ToolCallException('childDesign: The card has no parent card. Pass parentCardId, or leave childDesign out.');
        }

        $targets = $this->choices->forChild($parent, $documentIds);
        if (null === $childDesign) {
            $parentChanges = $parentSet && true !== $parent->id?->equals($current?->parent?->id);
            if ($parentChanges && true === $targets?->choiceRequired) {
                throw new ToolCallException('childDesign: This card joins a parent whose tech design is approved. Pass "inherit" if that design covers the card, or "own" if the card needs a design of its own.');
            }

            return null;
        }
        if (null === $targets || !\in_array($childDesign, $targets->declared, true)) {
            throw new ToolCallException('childDesign: The workflow of this project declares no such choice. Leave childDesign out.');
        }
        if (ChildDesignChoices::OWN === $childDesign && $statusGiven) {
            throw new ToolCallException('childDesign: "own" sets the column of the card, so pass no status with it.');
        }
        if (ChildDesignChoices::INHERIT === $childDesign && !$targets->inheritAvailable) {
            throw new ToolCallException('childDesign: The parent card has no tech design to inherit. Pass "own" to give this card a design of its own.');
        }

        return $childDesign;
    }

    private function parent(string $parentCardId): ?Card
    {
        try {
            return $this->subjects->requireCard($parentCardId, McpBoundProjectVoter::CARD_WRITE);
        } catch (ToolCallException) {
            return null;
        }
    }
}
