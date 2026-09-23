<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\GitHub\Service\OneTimeHookSecret;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The Repositories section of the Connections page. The Project module names
 * it in its template only, so it never imports this module.
 *
 * Props: `project`, passed as an object with `:project`.
 */
#[AsTwigComponent(name: 'GitHubRepositories')]
final class GitHubRepositoriesComponent
{
    public Project $project;

    public bool $visible = false;

    public bool $appConfigured = false;

    public bool $hookAvailable = false;

    public ?GitHubHook $hook = null;

    /** Set on the render right after a create or a rotation, never after. */
    public ?string $newSecret = null;

    /** @var list<ForgeRepository> */
    public array $repositories = [];

    public \DateTimeImmutable $now;

    public function __construct(
        private readonly Security $security,
        private readonly ForgeRepositoryRepository $forgeRepositories,
        private readonly GitHubHookRepository $gitHubHooks,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly HookSecretKey $hookSecretKey,
        private readonly OneTimeHookSecret $oneTimeHookSecret,
    ) {
        $this->now = new \DateTimeImmutable();
    }

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->visible = $this->security->isGranted(GitHubConnectionVoter::MANAGE, $project);
        if (!$this->visible) {
            return;
        }

        $this->appConfigured = $this->appConfiguration->isConfigured();
        $this->hookAvailable = $this->hookSecretKey->isReadable();
        $this->repositories = $this->forgeRepositories->findByProject($project);

        // Loading a hook decrypts its secret, which throws without the key.
        if ($this->hookAvailable) {
            $this->hook = $this->gitHubHooks->findOneByProject($project);
            $this->newSecret = $this->oneTimeHookSecret->take($project);
        }
    }
}
