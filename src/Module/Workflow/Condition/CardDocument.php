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

final readonly class CardDocument implements Condition
{
    #[\Override]
    public static function key(): string
    {
        return 'card.document';
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
            new Parameter('tag', ParameterType::String),
            new Parameter('status', ParameterType::String, required: false, choices: DocumentStatus::values()),
        ];
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
        $status = ParameterValue::optionalString($params, 'status');

        return array_any(
            $facts->card->documents,
            static fn ($document): bool => \in_array($tag, $document->tags, true) && (null === $status || $status === $document->status),
        );
    }

    #[\Override]
    public function waitingFor(array $params, bool $negated = false): TranslatableMessage
    {
        $tag = ParameterValue::string($params, 'tag');
        $status = ParameterValue::optionalString($params, 'status');
        if (null === $status) {
            return new TranslatableMessage($negated ? 'workflow.waiting.not.card_document' : 'workflow.waiting.card_document', ['%tag%' => $tag]);
        }

        return new TranslatableMessage(
            $negated ? 'workflow.waiting.not.card_document_status' : 'workflow.waiting.card_document_status',
            ['%tag%' => $tag, '%status%' => new TranslatableMessage(DocumentStatus::from($status)->translationKey())],
        );
    }
}
