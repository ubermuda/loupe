<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Fact\FactKey;
use App\Module\Workflow\Fact\Facts;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardDocumentChangesRequested implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document_changes_requested';
    }

    #[\Override]
    public static function parameters(): array
    {
        return [new Parameter('tag', ParameterType::String)];
    }

    #[\Override]
    public function reads(array $params): array
    {
        return [FactKey::Documents];
    }

    #[\Override]
    public function evaluate(Facts $facts, array $params): bool
    {
        $tag = ParameterValue::string($params, 'tag');

        return array_any(
            $facts->card->documents,
            static fn ($document): bool => DocumentStatus::ChangesRequested->value === $document->status && \in_array($tag, $document->tags, true),
        );
    }

    #[\Override]
    public function waitingFor(array $params): TranslatableMessage
    {
        return new TranslatableMessage('workflow.waiting.card_document_changes_requested', ['%tag%' => ParameterValue::string($params, 'tag')]);
    }
}
