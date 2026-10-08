<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Service\CardEventCause;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\Rule;

/**
 * Links the document of the parent card that carries the tag to the card, whatever the status of the document.
 * Two such documents give the one linked first. The card keeps the documents it has.
 */
final readonly class LinkDocument implements Action
{
    public const string NO_PARENT_DOCUMENT = 'no-parent-document';
    public const string LINK_REFUSED = 'document-link-refused';

    public function __construct(
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function type(): ActionType
    {
        return ActionType::LinkDocument;
    }

    #[\Override]
    public function run(Rule $rule, Card $card, Facts $facts, WorkflowRuleState $state): ActionOutcome
    {
        $tag = ActionParams::string($rule, 'tag');
        $document = array_find($facts->card->parentDocuments, static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true));
        if (null === $document) {
            return ActionOutcome::refused(self::NO_PARENT_DOCUMENT);
        }
        $linked = array_map(static fn (DocumentFacts $document): string => $document->id, $facts->card->documents);
        if (\in_array($document->id, $linked, true)) {
            return ActionOutcome::done();
        }

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: CardReporter::System,
                documentIds: [...$linked, $document->id],
                cause: CardEventCause::workflowRule($rule->id),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::LINK_REFUSED);
        }

        return ActionOutcome::done();
    }
}
