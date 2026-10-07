<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores the wait switches, then asks for a reconcile of the whole project so its existing waits follow them. */
final readonly class UpdateInboxSettingsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InboxProjectSettingsRepository $inboxProjectSettings,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateInboxSettingsCommand $command): void
    {
        $project = $command->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));

        $settings = $this->em->wrapInTransaction(function () use ($command, $project, $projectId): InboxProjectSettings {
            // The lock also keeps two first saves from both inserting a row.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $settings = $this->inboxProjectSettings->findForProject($project);
            if (null === $settings) {
                $settings = new InboxProjectSettings($project);
                $this->em->persist($settings);
            } else {
                // A row loaded before the lock can hold stale switches, and a null switch keeps the stored value.
                $this->em->refresh($settings);
            }
            $settings->documentInReview = $command->documentInReview ?? $settings->documentInReview;
            $settings->runBlocked = $command->runBlocked ?? $settings->runBlocked;
            $settings->runGaveUp = $command->runGaveUp ?? $settings->runGaveUp;
            $settings->runWaitingForPerson = $command->runWaitingForPerson ?? $settings->runWaitingForPerson;
            $settings->pullRequestReady = $command->pullRequestReady ?? $settings->pullRequestReady;
            $settings->pullRequestFixStopped = $command->pullRequestFixStopped ?? $settings->pullRequestFixStopped;
            $settings->cardPaused = $command->cardPaused ?? $settings->cardPaused;
            $this->em->flush();

            // The Doctrine transport commits the message with this transaction.
            $this->bus->dispatch(new ReconcileCardWaits($projectId, null));

            return $settings;
        });

        $this->auditor->record(
            'inbox.settings_saved',
            AuditOutcome::Success,
            [
                'projectId' => $projectId,
                'documentInReview' => $settings->documentInReview,
                'runBlocked' => $settings->runBlocked,
                'runGaveUp' => $settings->runGaveUp,
                'runWaitingForPerson' => $settings->runWaitingForPerson,
                'pullRequestReady' => $settings->pullRequestReady,
                'pullRequestFixStopped' => $settings->pullRequestFixStopped,
                'cardPaused' => $settings->cardPaused,
            ],
            new AuditSubject('project', $projectId),
        );
    }
}
