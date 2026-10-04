<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\Parameter;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use Symfony\Component\Translation\TranslatableMessage;

final readonly class CardDocumentApproved implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document_approved';
    }

    #[\Override]
    public static function source(): string
    {
        return 'workflow.source.board';
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
            static fn ($document): bool => DocumentStatus::Approved->value === $document->status && \in_array($tag, $document->tags, true),
        );
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document_approved' : 'workflow.waiting.card_document_approved', ['%tag%' => ParameterValue::string($params, 'tag')]);
    }
}
