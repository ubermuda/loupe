<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The wait switches of a project. A project without a row has every switch on. */
#[ORM\Entity(repositoryClass: InboxProjectSettingsRepository::class)]
#[ORM\Table(name: 'inbox_project_settings')]
class InboxProjectSettings
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(options: ['default' => true])]
    public bool $documentInReview = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $runBlocked = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $runGaveUp = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $runWaitingForPerson = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $pullRequestReady = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $pullRequestFixStopped = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $cardPaused = true;

    public function __construct(
        #[ORM\JoinColumn(nullable: false)]
        #[ORM\OneToOne(targetEntity: Project::class)]
        public readonly Project $project,
    ) {
    }

    public function isOn(InboxCardWaitTrigger $trigger): bool
    {
        return match ($trigger) {
            InboxCardWaitTrigger::DocumentInReview => $this->documentInReview,
            InboxCardWaitTrigger::RunBlocked => $this->runBlocked,
            InboxCardWaitTrigger::RunGaveUp => $this->runGaveUp,
            InboxCardWaitTrigger::RunWaitingForPerson => $this->runWaitingForPerson,
            InboxCardWaitTrigger::PullRequestReady => $this->pullRequestReady,
            InboxCardWaitTrigger::PullRequestFixStopped => $this->pullRequestFixStopped,
            InboxCardWaitTrigger::CardPaused => $this->cardPaused,
        };
    }
}
