<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxItemCloser;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Answers a question with options, free text or both, as the question allows. */
final readonly class AnswerInboxItemHandler
{
    public function __construct(
        private InboxItemCloser $closer,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AnswerInboxItemCommand $command): InboxItem
    {
        $item = $command->item;
        if (InboxItemKind::Question !== $item->kind) {
            throw new DomainErrors(['selectedOptions' => 'inbox.answer.error.not_a_question']);
        }

        $selected = self::parseIndexes($command->selectedOptions, \count($item->options));
        if (null === $selected) {
            throw new DomainErrors(['selectedOptions' => 'inbox.answer.error.unknown_option']);
        }
        if (!$item->multiple && \count($selected) > 1) {
            throw new DomainErrors(['selectedOptions' => 'inbox.answer.error.one_option']);
        }

        $text = trim($command->answerText);
        if ('' !== $text && !$item->freeText) {
            throw new DomainErrors(['answerText' => 'inbox.answer.error.no_free_text']);
        }
        if ([] === $selected && '' === $text) {
            throw new DomainErrors([$item->freeText && [] === $item->options ? 'answerText' : 'selectedOptions' => 'inbox.answer.error.empty']);
        }

        $this->closer->close($item, InboxItemState::Answered, 'selectedOptions', static function (InboxItem $item) use ($selected, $text): void {
            $item->selectedOptions = $selected;
            $item->answerText = '' === $text ? null : $text;
            $item->closeNote = null;
        });

        $this->auditor->record(
            'inbox.item_answered',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'projectId' => (string) $item->project->id,
                'optionCount' => \count($selected),
                'hasText' => '' !== $text,
            ],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }

    /**
     * The distinct indexes in ascending order, or null when one is not an option.
     *
     * @return list<int>|null
     */
    private static function parseIndexes(string $raw, int $optionCount): ?array
    {
        $indexes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            if (1 !== preg_match('~^\d{1,4}$~', $part) || (int) $part >= $optionCount) {
                return null;
            }
            $indexes[(int) $part] = (int) $part;
        }
        ksort($indexes);

        return array_values($indexes);
    }
}
