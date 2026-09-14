<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxSessionAsks;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Adds an existing open item to the session's open ask, or to a new ask when
 * the session has none. One answer then counts toward every ask that holds it.
 */
final readonly class JoinInboxAskHandler
{
    public const string ITEM_NOT_OPEN = 'inbox.item.error.not_open';

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxSessionAsks $sessionAsks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(JoinInboxAskCommand $command): JoinInboxAskView
    {
        $item = $command->item;
        $project = $item->project;

        try {
            $view = $this->em->wrapInTransaction(function () use ($command, $item, $project): JoinInboxAskView|string {
                // The project first and the item second, the order a card move
                // takes them in, so the two never wait on each other.
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                if (InboxItemState::Open !== $this->inboxItems->lockedState($item)) {
                    return self::ITEM_NOT_OPEN;
                }

                $sessionAsk = $this->sessionAsks->openOrExtend($project, $command->sessionId, $command->bridgeId, null);
                if (null === $sessionAsk) {
                    return InboxSessionAsks::SESSION_ASK_ELSEWHERE;
                }

                $added = $this->sessionAsks->add($sessionAsk->ask, $item);
                $this->sessionAsks->closeWhenNothingBlocks($sessionAsk, new \DateTimeImmutable());
                $this->em->flush();

                return new JoinInboxAskView($sessionAsk->ask, $item, extended: !$sessionAsk->opened, added: $added);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), InboxSessionAsks::OPEN_SESSION_INDEX)) {
                throw $e;
            }

            throw new DomainErrors(['sessionId' => InboxSessionAsks::SESSION_ASK_ELSEWHERE]);
        }

        if (InboxSessionAsks::SESSION_ASK_ELSEWHERE === $view) {
            throw new DomainErrors(['sessionId' => $view]);
        }
        if (\is_string($view)) {
            throw new DomainErrors(['itemId' => $view]);
        }

        $this->auditor->record(
            'inbox.item_joined',
            AuditOutcome::Success,
            [
                'askId' => (string) $view->ask->id,
                'itemId' => (string) $item->id,
                'itemNumber' => $item->number,
                'projectId' => (string) $project->id,
                'sessionId' => (string) $command->sessionId,
                'extended' => $view->extended,
                'added' => $view->added,
                'closed' => null !== $view->ask->closedAt,
            ],
            new AuditSubject('inbox_ask', (string) $view->ask->id),
        );

        return $view;
    }
}
