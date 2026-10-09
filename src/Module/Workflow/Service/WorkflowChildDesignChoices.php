<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\CardMoveGuard;
use App\Module\Board\Service\ChildDesignChoices;
use App\Module\Board\Service\ChildDesignTargets;
use App\Module\Workflow\Action\ActionOutcomeKind;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\LinkDocument;
use App\Module\Workflow\Action\MoveCard;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the `childChoices` of the stored template and runs the actions of a choice on the card, the way the answer of an owner does. */
#[AsAlias(ChildDesignChoices::class)]
final readonly class WorkflowChildDesignChoices implements ChildDesignChoices
{
    private const string APPROVED = 'approved';

    public function __construct(
        private WorkflowAutomation $automation,
        private TemplateSource $templates,
        private CardDocumentRepository $cardDocuments,
        private FactsBuilder $factsBuilder,
        private Actions $actions,
        private MoveCard $moveCard,
        private CardMoveGuard $moveGuard,
        private EntityManagerInterface $em,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function forChild(Card $parent, array $documentIds): ?ChildDesignTargets
    {
        $template = $this->templateFor($parent);
        if (null === $template || [] === $template->childChoices) {
            return null;
        }
        $tag = $this->inheritTag($template);
        $parentDocuments = null === $tag ? [] : $this->cardDocuments->findStatusesAndTagsForCard($parent);
        $tagged = array_filter($parentDocuments, static fn (array $document): bool => \in_array($tag, $document['tags'], true));
        $approved = array_filter($tagged, static fn (array $document): bool => self::APPROVED === $document['status']);

        return new ChildDesignTargets(
            declared: array_keys($template->childChoices),
            choiceRequired: null !== $tag && [] !== $approved && !$this->linksApproved($documentIds, $tag),
            inheritAvailable: [] !== $tagged,
        );
    }

    #[\Override]
    public function write(\Closure $write, string $choice, ?CardEventCause $cause = null): Card
    {
        return $this->em->wrapInTransaction(function () use ($write, $choice, $cause): Card {
            $card = $write();
            $parent = $card->parent ?? throw new ChildDesignRefused('childDesign: The card has no parent card, so there is nothing to inherit.');
            $calls = $this->templateFor($parent)?->childChoices[$choice]
                ?? throw new ChildDesignRefused(\sprintf('childDesign: The workflow of this project declares no "%s" choice.', $choice));

            $rule = new Rule('child-design-'.$choice, null, new AllOf([]), $calls[0]);
            $state = new WorkflowRuleState($card, $card->project, $rule->id);
            foreach ($calls as $call) {
                $this->run($call, $rule, $card, $state, $choice, $cause);
            }
            $this->em->flush();

            return $card;
        });
    }

    private function run(ActionCall $call, Rule $rule, Card $card, WorkflowRuleState $state, string $choice, ?CardEventCause $cause): void
    {
        if (ActionType::Move === $call->type) {
            $column = $this->moveCard->columnFor($card, (string) ($call->params['to'] ?? ''));
            if (null !== $column && !$this->moveGuard->allows($card, $column, CardReporter::Agent, $cause)) {
                throw new CardManaged($card->number);
            }
        }

        $result = $this->actions->get($call->type)->run(
            new Rule($rule->id, $rule->slot, $rule->when, $call, $rule->origin),
            $card,
            $this->factsBuilder->build($card, $this->clock->now()),
            $state,
        );
        if (ActionOutcomeKind::Done !== $result->kind) {
            throw new ChildDesignRefused(self::refusal($choice, $result->code ?? $result->kind->value));
        }
    }

    private static function refusal(string $choice, string $code): string
    {
        return match ($code) {
            LinkDocument::NO_PARENT_DOCUMENT => 'childDesign: The parent card has no tech design to inherit. Pass "own" to give this card a design of its own.',
            'workflow-slot-missing' => 'childDesign: This board has no column for the Tech design step, so "own" cannot move the card there.',
            default => \sprintf('childDesign: The workflow refused the "%s" choice (%s).', $choice, $code),
        };
    }

    private function templateFor(Card $card): ?Template
    {
        if (!$this->automation->runsFor($card->project)) {
            return null;
        }
        try {
            return $this->templates->forProject($card->project->id ?? throw new \LogicException('A stored project has an id.'));
        } catch (TemplateMissing) {
            return null;
        }
    }

    private function inheritTag(Template $template): ?string
    {
        foreach ($template->childChoices[self::INHERIT] ?? [] as $call) {
            if (ActionType::LinkDocument === $call->type && \is_string($call->params['tag'] ?? null)) {
                return $call->params['tag'];
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
