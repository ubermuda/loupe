<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\PullRequestComment;
use App\Module\Board\Entity\PullRequestCommentState;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/** The board automation settings of a project, with the defaults when the project saved none. */
final readonly class BoardAutomation
{
    public function __construct(
        private BoardAutomationSettingsRepository $boardAutomationSettings,
        private PullRequestCommentRepository $pullRequestComments,
        private EntityManagerInterface $em,
    ) {
    }

    /** Answers null when a later comment posted. */
    public function newestFailedComment(Project $project): ?PullRequestComment
    {
        $comment = $this->pullRequestComments->findNewestSettled($project);

        return PullRequestCommentState::Failed === $comment?->state ? $comment : null;
    }

    /** Never persists, so a project that saved nothing keeps no row. */
    public function settingsOf(Project $project): BoardAutomationSettings
    {
        return $this->boardAutomationSettings->findOneByProject($project) ?? new BoardAutomationSettings($project);
    }

    /** Persists a new row when the project has none. The caller flushes. */
    public function settingsForUpdate(Project $project): BoardAutomationSettings
    {
        $settings = $this->boardAutomationSettings->findOneByProject($project);
        if (null === $settings) {
            $settings = new BoardAutomationSettings($project);
            $this->em->persist($settings);
        }

        return $settings;
    }
}
