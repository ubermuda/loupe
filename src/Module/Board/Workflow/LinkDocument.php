<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionDescription;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\ActionTraits;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardEventCause;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;

/**
 * Links the document of the parent card that carries the tag to the card, whatever the status of the document.
 * Of two such documents, an approved one wins, then the one linked first. The card keeps the documents it has.
 */
final readonly class LinkDocument implements Action
{
    public const string NO_PARENT_DOCUMENT = 'no-parent-document';
    public const string LINK_REFUSED = 'document-link-refused';

    public const string KEY = 'link-document';
    private const string APPROVED = 'approved';

    public function __construct(
        private CardRepository $cards,
        private UpdateCardHandler $updateCard,
    ) {
    }

    #[\Override]
    public static function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [
            new Parameter('from', ParameterType::String, fixed: 'parent'),
            new Parameter('tag', ParameterType::String),
        ];
    }

    #[\Override]
    public static function traits(): ActionTraits
    {
        return new ActionTraits(option: true, childChoice: true);
    }

    #[\Override]
    public function describe(array $params): ActionDescription
    {
        return new ActionDescription('workflow.settings.action.link_document', 'workflow.panel.action.link_document');
    }

    #[\Override]
    public function workKind(array $params): ?string
    {
        return null;
    }

    #[\Override]
    public function run(ActionContext $context): ActionOutcome
    {
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $tag = $context->string('tag');
        $tagged = array_filter($context->facts->get(ParentDocumentsFacts::class)->documents, static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true));
        $document = array_find($tagged, static fn (DocumentFacts $document): bool => self::APPROVED === $document->status) ?? array_first($tagged);
        if (null === $document) {
            return ActionOutcome::refused(self::NO_PARENT_DOCUMENT);
        }
        $linked = array_map(static fn (DocumentFacts $document): string => $document->id, $context->facts->get(DocumentsFacts::class)->documents);
        if (\in_array($document->id, $linked, true)) {
            return ActionOutcome::done();
        }

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                actor: Actor::System,
                documentIds: [...$linked, $document->id],
                cause: CardEventCause::workflowRule($context->ruleId),
            ));
        } catch (DomainErrors) {
            return ActionOutcome::refused(self::LINK_REFUSED);
        }

        return ActionOutcome::done();
    }
}
