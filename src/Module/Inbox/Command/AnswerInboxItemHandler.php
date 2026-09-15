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

        $text = trim($command->answerText);

        // Checked inside the lock, against the options as stored: an agent may
        // change the question after the page loaded it.
        $this->closer->respond($item, InboxItemState::Answered, 'selectedOptions', static function (InboxItem $item) use ($command, $text): ?array {
            $selected = self::parseIndexes($command->selectedOptions, \count($item->options));
            if (null === $selected) {
                return ['selectedOptions' => 'inbox.answer.error.unknown_option'];
            }
            if (!$item->multiple && \count($selected) > 1) {
                return ['selectedOptions' => 'inbox.answer.error.one_option'];
            }
            if ('' !== $text && !$item->freeText) {
                return ['answerText' => 'inbox.answer.error.no_free_text'];
            }
            if ([] === $selected && '' === $text) {
                return [$item->freeText && [] === $item->options ? 'answerText' : 'selectedOptions' => 'inbox.answer.error.empty'];
            }

            $item->selectedOptions = $selected;
            $item->answerText = '' === $text ? null : $text;
            $item->closeNote = null;

            return null;
        });

        $this->auditor->record(
            'inbox.item_answered',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'projectId' => (string) $item->project->id,
                'optionCount' => \count($item->selectedOptions),
                'hasText' => null !== $item->answerText,
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
