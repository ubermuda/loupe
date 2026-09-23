<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\GitHub\Service\HookSecretKey;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateGitHubHookHandler
{
    public function __construct(
        private HookSecretKey $hookSecretKey,
        private GitHubHookRepository $gitHubHooks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CreateGitHubHookCommand $command): GitHubHook
    {
        // Reading or writing a hook without the key throws, so check it first.
        if (!$this->hookSecretKey->isReadable()) {
            throw new DomainErrors(['hook' => 'github.repositories.flash.hook_unavailable']);
        }

        if (null !== $this->gitHubHooks->findOneByProject($command->project)) {
            throw new DomainErrors(['hook' => 'github.repositories.flash.hook_exists']);
        }

        $hook = new GitHubHook($command->project, GitHubHook::newKey(), GitHubHook::newSecret());
        $this->em->persist($hook);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent create won the race with the check above. The failed
            // flush closed the EntityManager, so nothing after this may use it.
            throw new DomainErrors(['hook' => 'github.repositories.flash.hook_exists']);
        }

        $this->auditor->record(
            'github.hook_created',
            AuditOutcome::Success,
            ['projectId' => (string) $command->project->id, 'hookId' => (string) $hook->id],
            new AuditSubject('project', (string) $command->project->id),
        );

        return $hook;
    }
}
