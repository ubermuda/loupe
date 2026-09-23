<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Fixtures the GitHub delivery tests share. */
trait GitHubDeliveryScenario
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function enableBoard(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $this->em()->flush();
    }

    private function project(string $label): Project
    {
        $user = new User(fullName: 'Riley', email: 'github-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, $label.'-'.uniqid());
        $this->em()->persist($user);
        $this->em()->persist($project);
        $this->em()->flush();

        return $project;
    }

    private function hook(Project $project): GitHubHook
    {
        $hook = new GitHubHook($project, GitHubHook::newKey(), GitHubHook::newSecret());
        $this->em()->persist($hook);
        $this->em()->flush();

        return $hook;
    }

    private function installation(Project $project, int $installationId, GitHubRepositorySelection $selection = GitHubRepositorySelection::Selected): GitHubInstallation
    {
        $installation = new GitHubInstallation($project, $installationId, 'acme', $selection);
        $this->em()->persist($installation);
        $this->em()->flush();

        return $installation;
    }

    private function owned(Project $project, int $repositoryId, string $path): void
    {
        $this->em()->persist(new ForgeRepository($project, 'github', (string) $repositoryId, $path));
        $this->em()->flush();
    }

    private function linkedCard(Project $project, string $repository, int $number): Card
    {
        $column = new BoardColumn($project, 'Work', 'work-'.uniqid(), 0);
        $card = new Card($project, $column, 'Ship it', '', random_int(1, 1_000_000));
        $link = new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number);
        foreach ([$column, $card, $link] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $card;
    }

    /** @param array<mixed> $payload */
    private function deliver(KernelBrowser $client, string $path, string $event, array $payload, string $secret): void
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $client->request(Request::METHOD_POST, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], content: $body);
    }

    /**
     * @param array<mixed> $extra
     *
     * @return array<mixed>
     */
    private function merged(int $repositoryId, string $path, int $number, array $extra = []): array
    {
        return [
            'action' => 'closed',
            'repository' => ['id' => $repositoryId, 'full_name' => $path],
            'pull_request' => ['number' => $number, 'merged' => true],
            ...$extra,
        ];
    }

    /** @return list<string> the card ids the outbox rows of the project name */
    private function outboxSubjects(Project $project): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = :project',
            ['project' => $project->id],
            ['project' => 'uuid'],
        );

        return array_map(static fn (mixed $payload): string => json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR)['subject']['id'], $rows);
    }

    /** @return list<string> */
    private function linkPaths(Card $card): array
    {
        return array_values(array_map(strval(...), $this->em()->getConnection()->fetchFirstColumn(
            'SELECT repository FROM board_card_pull_requests WHERE card_id = :card',
            ['card' => $card->id],
            ['card' => 'uuid'],
        )));
    }

    private function ownerOf(int $repositoryId): ?ForgeRepository
    {
        $this->em()->clear();
        $repositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);

        return $repositories->findOneByForgeAndExternalId('github', (string) $repositoryId);
    }
}
