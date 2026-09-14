<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Inbox\Service\InboxSessionAsks;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Hands items to the owner. The items go to the session's open ask, or to a
 * new ask when the session has none.
 */
final readonly class AskInboxHandler
{
    public const string ITEMS_EMPTY = 'inbox.ask.error.items_empty';
    public const string TITLE_BLANK = 'inbox.item.error.title_blank';
    public const string TITLE_TOO_LONG = 'inbox.item.error.title_too_long';
    public const string OPTION_BLANK = 'inbox.item.error.option_blank';
    public const string TO_DO_WITH_ANSWER = 'inbox.item.error.todo_with_answer';
    public const string QUESTION_WITHOUT_ANSWER = 'inbox.item.error.question_without_answer';
    public const string MULTIPLE_WITHOUT_OPTIONS = 'inbox.item.error.multiple_without_options';

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxSessionAsks $sessionAsks,
        private InboxLinkResolver $links,
        private InboxSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AskInboxCommand $command): AskInboxView
    {
        if ([] === $command->items) {
            throw new DomainErrors(['items' => self::ITEMS_EMPTY]);
        }

        // Every refusal comes before the transaction, for the reason in InboxLinkResolver.
        $drafts = [];
        foreach ($command->items as $index => $input) {
            $drafts[] = $this->draft($command, $index, $input);
        }
        $context = null === $command->context || '' === trim($command->context) ? null : trim($command->context);

        try {
            $view = $this->em->wrapInTransaction(function () use ($command, $drafts, $context): AskInboxView|string {
                // Serialises every ask in the project: the item numbers, and the
                // lookup of the session's open ask that decides open or extend.
                $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

                $sessionAsk = $this->sessionAsks->openOrExtend($command->project, $command->sessionId, $command->bridgeId, $context);
                if (null === $sessionAsk) {
                    return InboxSessionAsks::SESSION_ASK_ELSEWHERE;
                }

                $now = new \DateTimeImmutable();
                $number = $this->inboxItems->nextNumber($command->project);
                $created = [];
                foreach ($drafts as $draft) {
                    $item = new InboxItem(
                        project: $command->project,
                        number: $number++,
                        kind: $draft['kind'],
                        title: $draft['title'],
                        blocking: $draft['blocking'],
                        body: $draft['body'],
                        options: $draft['options'],
                        multiple: $draft['multiple'],
                        freeText: $draft['freeText'],
                        createdAt: $now,
                        searchLanguage: $command->project->searchLanguage,
                    );
                    foreach ($draft['cards'] as $card) {
                        $item->cards->add(new InboxItemCard($item, $card, $now));
                    }
                    foreach ($draft['documents'] as $document) {
                        $item->documents->add(new InboxItemDocument($item, $document, $now));
                    }
                    $this->em->persist($item);
                    $this->sessionAsks->add($sessionAsk->ask, $item);
                    $created[] = $item;
                }

                $this->sessionAsks->closeWhenNothingBlocks($sessionAsk, $now);
                $this->em->flush();

                foreach ($created as $item) {
                    $this->searchIndexer->index($item);
                }

                return new AskInboxView($sessionAsk->ask, $created, extended: !$sessionAsk->opened);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The project lock serialises one project only. A session that asks in
            // two projects at once meets the one-open-ask index instead.
            if (!str_contains($e->getMessage(), InboxSessionAsks::OPEN_SESSION_INDEX)) {
                throw $e;
            }

            throw new DomainErrors(['sessionId' => InboxSessionAsks::SESSION_ASK_ELSEWHERE]);
        }

        if (\is_string($view)) {
            throw new DomainErrors(['sessionId' => $view]);
        }

        // After the commit, for the reason in CreateCardHandler. No title and no
        // body, because they are sentences an agent wrote.
        $this->auditor->record(
            'inbox.items_asked',
            AuditOutcome::Success,
            [
                'askId' => (string) $view->ask->id,
                'projectId' => (string) $command->project->id,
                'sessionId' => (string) $command->sessionId,
                'bridgeId' => null === $command->bridgeId ? null : (string) $command->bridgeId,
                'itemNumbers' => implode(',', array_map(static fn (InboxItem $item): int => $item->number, $view->items)),
                'extended' => $view->extended,
                'closed' => null !== $view->ask->closedAt,
            ],
            new AuditSubject('inbox_ask', (string) $view->ask->id),
        );

        return $view;
    }

    /**
     * @return array{kind: InboxItemKind, title: string, body: ?string, options: list<string>, multiple: bool, freeText: bool, blocking: bool, cards: list<\App\Module\Board\Entity\Card>, documents: list<\App\Module\Review\Entity\Document>}
     */
    private function draft(AskInboxCommand $command, int $index, AskInboxItem $input): array
    {
        $field = static fn (string $name): string => \sprintf('items[%d].%s', $index, $name);

        $title = trim($input->title);
        if ('' === $title) {
            throw new DomainErrors([$field('title') => self::TITLE_BLANK]);
        }
        if (mb_strlen($title) > InboxItem::MAX_TITLE_LENGTH) {
            throw new DomainErrors([$field('title') => self::TITLE_TOO_LONG]);
        }

        $options = array_map(trim(...), $input->options);
        if (\in_array('', $options, true)) {
            throw new DomainErrors([$field('options') => self::OPTION_BLANK]);
        }

        if (InboxItemKind::Todo === $input->kind && ([] !== $options || $input->multiple || $input->freeText)) {
            throw new DomainErrors([$field('options') => self::TO_DO_WITH_ANSWER]);
        }
        if (InboxItemKind::Question === $input->kind && [] === $options && !$input->freeText) {
            throw new DomainErrors([$field('options') => self::QUESTION_WITHOUT_ANSWER]);
        }
        if ($input->multiple && \count($options) < 2) {
            throw new DomainErrors([$field('multiple') => self::MULTIPLE_WITHOUT_OPTIONS]);
        }

        $body = null === $input->body || '' === trim($input->body) ? null : $input->body;

        return [
            'kind' => $input->kind,
            'title' => $title,
            'body' => $body,
            'options' => $options,
            'multiple' => $input->multiple,
            'freeText' => $input->freeText,
            'blocking' => $input->blocking ?? InboxItemKind::Question === $input->kind,
            'cards' => $this->links->cards($command->project, $input->cardIds, $field('cardIds')),
            'documents' => $this->links->documents($command->project, $input->documentIds, $field('documentIds')),
        ];
    }
}
