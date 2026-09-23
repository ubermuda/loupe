<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\GitHub\Service\HookSecretKey;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class RotateGitHubHookSecretHandler
{
    public function __construct(
        private HookSecretKey $hookSecretKey,
        private GitHubHookRepository $gitHubHooks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RotateGitHubHookSecretCommand $command): GitHubHook
    {
        // Loading a hook decrypts its secret, which throws without the key.
        if (!$this->hookSecretKey->isReadable()) {
            throw new DomainErrors(['hook' => 'github.repositories.flash.hook_unavailable']);
        }

        $hook = $this->gitHubHooks->findOneByProject($command->project)
            ?? throw new DomainErrors(['hook' => 'github.repositories.flash.hook_missing']);

        $hook->secret = GitHubHook::newSecret();
        $this->em->flush();

        $this->auditor->record(
            'github.hook_secret_rotated',
            AuditOutcome::Success,
            ['projectId' => (string) $command->project->id, 'hookId' => (string) $hook->id],
            new AuditSubject('project', (string) $command->project->id),
        );

        return $hook;
    }
}
