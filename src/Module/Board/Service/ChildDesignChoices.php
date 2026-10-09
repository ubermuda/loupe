<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Workflow\LinkDocument;
use App\Module\Board\Workflow\MoveCard;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ChildChoices;
use App\Module\Workflow\Contract\ChildChoiceStep;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Reads what the workflow says about the choice an agent states for a card it files under a parent, and writes the card with that choice. */
final readonly class ChildDesignChoices
{
    public const string INHERIT = ChildChoices::INHERIT;
    public const string OWN = ChildChoices::OWN;
    public const array CHOICES = ChildChoices::CHOICES;

    private const string APPROVED = 'approved';
    private const string SLOT_MISSING = 'workflow-slot-missing';

    public function __construct(
        private ChildChoices $choices,
        private CardDocumentRepository $cardDocuments,
        private CardMoveGuard $moveGuard,
        private EntityManagerInterface $em,
        private Connection $connection,
    ) {
    }

    /**
     * Null when the workflow of the project declares no choices, or does not run for the project.
     *
     * @param list<string> $documentIds the ids of the documents the card links once the call has run
     */
    public function forChild(Card $parent, array $documentIds): ?ChildDesignTargets
    {
        $steps = $this->choices->forProject($parent->project->requireId());
        if ([] === $steps) {
            return null;
        }
        $tag = self::inheritTag($steps);
        $parentDocuments = null === $tag ? [] : $this->cardDocuments->findStatusesAndTagsForCard($parent);
        $tagged = array_filter($parentDocuments, static fn (array $document): bool => \in_array($tag, $document['tags'], true));
        $approved = array_filter($tagged, static fn (array $document): bool => self::APPROVED === $document['status']);

        return new ChildDesignTargets(
            declared: array_keys($steps),
            choiceRequired: null !== $tag && [] !== $approved && !$this->linksApproved($documentIds, $tag),
            inheritAvailable: [] !== $tagged,
        );
    }

    /**
     * Runs the card write and the actions of the choice in one transaction. A refusal rolls both back.
     * For a card that exists and keeps its parent, the move the choice makes is checked before the write, so a refusal leaves no trace.
     *
     * @param \Closure(): Card $write
     *
     * @throws ChildDesignRefused when the workflow declares no such choice, or an action refuses
     * @throws CardManaged        when the workflow does not allow the move that `own` makes
     */
    public function write(Project $project, \Closure $write, string $choice, ?CardEventCause $cause = null, ?Card $existing = null): Card
    {
        // The card handlers record their audit event outside this transaction, so refuse what can be known before the write.
        $steps = $this->choices->forProject($project->requireId())[$choice] ?? throw new ChildDesignRefused(self::refusal($choice, ChildChoices::NO_CHOICE));
        foreach ($steps as $step) {
            if (MoveCard::KEY === $step->key && !isset($step->params['from']) && null === $step->to) {
                throw new ChildDesignRefused(self::refusal($choice, self::SLOT_MISSING));
            }
        }
        if (null !== $existing) {
            // The write can change the column, so the check stops at a move with a `from` slot, and the check after the write covers it.
            $this->checkMoves($existing, $choice, $cause, withFrom: false);
        }

        return $this->em->wrapInTransaction(function () use ($write, $choice, $cause): Card {
            $card = $write();
            if (null === $card->parent) {
                throw new ChildDesignRefused('childDesign: The card has no parent card, so there is nothing to inherit.');
            }
            $this->checkMoves($card, $choice, $cause, withFrom: true);
            $refusal = $this->choices->run($card->id ?? throw new \LogicException('A stored card has an id.'), $choice);
            if (null !== $refusal) {
                throw new ChildDesignRefused(self::refusal($choice, $refusal));
            }
            $this->em->flush();

            return $card;
        });
    }

    /** Checks each move from the column the steps before it reach, as an agent move. */
    private function checkMoves(Card $card, string $choice, ?CardEventCause $cause, bool $withFrom): void
    {
        $snapshot = $card->snapshot();
        foreach ($this->choices->forProject($card->project->requireId())[$choice] ?? [] as $step) {
            if (MoveCard::KEY !== $step->key || null === $step->to) {
                continue;
            }
            if (isset($step->params['from'])) {
                if (!$withFrom) {
                    return;
                }
                if (true !== $step->from?->id->equals($snapshot->column->id)) {
                    continue;
                }
            }
            if (!$this->moveGuard->allows($snapshot, $step->to, Actor::Agent, $cause)) {
                throw new CardManaged($card->number);
            }
            $snapshot = new CardSnapshot($snapshot->id, $snapshot->projectId, $snapshot->number, $snapshot->type, $step->to, $snapshot->parentId, $snapshot->parentNumber, $snapshot->parentColumn);
        }
    }

    private static function refusal(string $choice, string $code): string
    {
        return match ($code) {
            LinkDocument::NO_PARENT_DOCUMENT => 'childDesign: The parent card has no tech design to inherit. Pass "own" to give this card a design of its own.',
            self::SLOT_MISSING => 'childDesign: This board has no column for the Tech design step, so "own" cannot move the card there.',
            ChildChoices::NO_CHOICE => \sprintf('childDesign: The workflow of this project declares no "%s" choice.', $choice),
            default => \sprintf('childDesign: The workflow refused the "%s" choice (%s).', $choice, $code),
        };
    }

    /** @param array<string, list<ChildChoiceStep>> $steps */
    private static function inheritTag(array $steps): ?string
    {
        foreach ($steps[self::INHERIT] ?? [] as $step) {
            if (LinkDocument::KEY === $step->key && \is_string($step->params['tag'] ?? null)) {
                return $step->params['tag'];
            }
        }

        return null;
    }

    /** @param list<string> $documentIds */
    private function linksApproved(array $documentIds, string $tag): bool
    {
        $ids = array_values(array_filter($documentIds, Uuid::isValid(...)));
        if ([] === $ids) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM documents d JOIN document_tags dt ON dt.document_id = d.id JOIN tags t ON t.id = dt.tag_id
             WHERE d.id IN (:ids) AND d.status = :status AND t.name = :tag AND d.archived_at IS NULL LIMIT 1',
            ['ids' => $ids, 'status' => self::APPROVED, 'tag' => $tag],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
