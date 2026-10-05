<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Messenger\RefreshPullRequestStateHandler;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Fixtures the GitHub delivery tests share. */
trait GitHubDeliveryScenario
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** Sets the App id and key, so an installation repository gets state reads. Call it before the first request. */
    private function configureAppKey(): void
    {
        self::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, 'github_app_webhook_test', '123456', 'key'));
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

    private function owned(Project $project, int $repositoryId, string $path, ForgeRepositorySource $source = ForgeRepositorySource::Hook, ?int $installationId = null): void
    {
        $this->em()->persist(new ForgeRepository($project, 'github', (string) $repositoryId, $path, $source, null === $installationId ? null : (string) $installationId));
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

    private function trackedPullRequest(Project $project, string $repository, int $number, string $headSha, string $baseBranch = 'main'): string
    {
        $pullRequest = new ForgePullRequest($project, 'github', $repository, $number);
        $pullRequest->headSha = $headSha;
        $pullRequest->baseBranch = $baseBranch;
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return (string) $pullRequest->id;
    }

    /** @return list<array{string, bool}> the pull request id of each queued refresh, and whether it waits */
    private function queuedRefreshes(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $refreshes = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshPullRequestState) {
                $refreshes[] = [$message->pullRequestId, null !== $envelope->last(DelayStamp::class)];
            }
        }

        return $refreshes;
    }

    /** @return list<array{?PullRequestReview, ?string}> the verdict and the review id of each queued refresh */
    private function queuedVerdicts(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $verdicts = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshPullRequestState) {
                $verdicts[] = [$message->verdict, $message->reviewId];
            }
        }

        return $verdicts;
    }

    /** Runs each queued refresh through its handler, as the worker would. */
    private function drainRefreshes(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = self::getContainer()->get(RefreshPullRequestStateHandler::class);
        self::assertInstanceOf(RefreshPullRequestStateHandler::class, $handler);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RefreshPullRequestState) {
                $handler($message);
            }
        }
    }

    /** @return list<string> the type of each outbox row of the project, in order */
    private function outboxTypes(Project $project): array
    {
        return array_values(array_map(strval(...), $this->em()->getConnection()->fetchFirstColumn(
            'SELECT type FROM outbox_events WHERE project_id = :project ORDER BY sequence',
            ['project' => $project->id],
            ['project' => 'uuid'],
        )));
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

    private function rowOf(Project $project, int $repositoryId): ?ForgeRepository
    {
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project is gone.');

        return $this->forgeRepositoryRepository()->findOneForProject($project, 'github', (string) $repositoryId);
    }

    private function installationOwnerOf(int $repositoryId): ?ForgeRepository
    {
        $this->em()->clear();

        return $this->forgeRepositoryRepository()->findInstallationRow('github', (string) $repositoryId);
    }

    private function forgeRepositoryRepository(): ForgeRepositoryRepository
    {
        $repositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);

        return $repositories;
    }
}
