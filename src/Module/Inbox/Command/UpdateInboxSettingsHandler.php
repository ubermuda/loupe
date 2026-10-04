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

        $this->em->wrapInTransaction(function () use ($command, $project, $projectId): void {
            // The lock also keeps two first saves from both inserting a row.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $settings = $this->inboxProjectSettings->findForProject($project);
            if (null === $settings) {
                $settings = new InboxProjectSettings($project);
                $this->em->persist($settings);
            }
            $settings->documentInReview = $command->documentInReview;
            $settings->runBlocked = $command->runBlocked;
            $settings->runGaveUp = $command->runGaveUp;
            $settings->runWaitingForPerson = $command->runWaitingForPerson;
            $settings->pullRequestReady = $command->pullRequestReady;
            $settings->pullRequestFixStopped = $command->pullRequestFixStopped;
            $settings->cardPaused = $command->cardPaused;
            $this->em->flush();

            // The Doctrine transport commits the message with this transaction.
            $this->bus->dispatch(new ReconcileCardWaits($projectId, null));
        });

        $this->auditor->record(
            'inbox.settings_saved',
            AuditOutcome::Success,
            [
                'projectId' => $projectId,
                'documentInReview' => $command->documentInReview,
                'runBlocked' => $command->runBlocked,
                'runGaveUp' => $command->runGaveUp,
                'runWaitingForPerson' => $command->runWaitingForPerson,
                'pullRequestReady' => $command->pullRequestReady,
                'pullRequestFixStopped' => $command->pullRequestFixStopped,
                'cardPaused' => $command->cardPaused,
            ],
            new AuditSubject('project', $projectId),
        );
    }
}
