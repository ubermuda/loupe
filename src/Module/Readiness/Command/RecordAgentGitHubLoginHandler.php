<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RecordAgentGitHubLoginHandler
{
    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RecordAgentGitHubLoginCommand $command): void
    {
        $project = $command->project;
        $login = '' === $command->login ? null : $command->login;

        if (null !== $login) {
            foreach ($this->gitHubInstallations->findByProject($project) as $installation) {
                if (0 === strcasecmp($installation->accountLogin, $login)) {
                    throw new DomainErrors(['login' => 'readiness.agent_account.error.installer_login']);
                }
            }
        }

        $project->agentGitHubLogin = $login;
        $this->em->flush();

        $this->auditor->record(
            'readiness.agent_login_recorded',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'cleared' => null === $login],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
