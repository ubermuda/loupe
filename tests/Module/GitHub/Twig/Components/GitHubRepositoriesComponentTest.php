<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Twig\Components;

use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class GitHubRepositoriesComponentTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_sees_the_empty_state_and_the_create_action(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('empty');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#repositories');
        self::assertCount(1, $section);
        self::assertStringContainsString('reach the cards that link its pull requests', $section->text());
        self::assertCount(0, $section->filter('[data-forge-repository]'));
        self::assertCount(1, $section->filter('[data-testid="github-hook-create"]'));
        self::assertCount(0, $section->filter('[data-testid="github-hook-url"]'));
    }

    public function test_a_stranger_cannot_open_the_page_of_another_project(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->connectPage($client, $project);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_each_repository_shows_its_path_and_its_health(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('health');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/waiting');
        $this->repositoryOf($project, 'acme/working', new \DateTimeImmutable('-2 hours'));
        $this->repositoryOf($project, 'acme/quiet', new \DateTimeImmutable('-31 days'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $rows = [];
        $crawler->filter('#repositories [data-forge-repository]')->each(static function ($row) use (&$rows): void {
            $rows[trim($row->filter('[data-testid="forge-repository-path"]')->text())] = trim($row->filter('[data-testid="forge-repository-health"]')->text());
        });

        self::assertSame(['acme/waiting', 'acme/working', 'acme/quiet'], array_keys($rows));
        self::assertSame('Waiting for the first delivery', $rows['acme/waiting']);
        self::assertStringStartsWith('Working', $rows['acme/working']);
        self::assertStringStartsWith('Quiet', $rows['acme/quiet']);
    }

    public function test_an_existing_hook_shows_its_url_and_health_but_never_its_secret(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('hook');
        $project = $this->projectOf($owner);
        $hook = $this->hookOf($project);
        $hook->refused(new \DateTimeImmutable('-1 hour'), 'bad_signature');
        $this->em()->flush();
        $secret = $hook->secret;
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $section = $crawler->filter('#repositories');
        self::assertStringEndsWith('/webhooks/forge/github/'.$hook->hookKey, trim($section->filter('[data-testid="github-hook-url"]')->text()));
        self::assertStringStartsWith('http://localhost/', trim($section->filter('[data-testid="github-hook-url"]')->text()));
        self::assertStringContainsString('application/json', $section->text());
        $health = trim($section->filter('[data-testid="github-hook-health"]')->text());
        self::assertStringStartsWith('Failing', $health);
        self::assertStringContainsString('signature', $health);
        self::assertCount(0, $section->filter('[data-testid="github-hook-secret"]'));
        self::assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());
        self::assertCount(1, $section->filter('[data-testid="github-hook-rotate"]'));
    }

    public function test_an_unreadable_key_says_webhooks_are_off_and_hides_the_actions(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nokey');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider('')));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#repositories');
        self::assertCount(0, $section->filter('[data-testid="github-hook-create"]'));
        self::assertStringContainsString('The operator has not enabled forge connections', $section->text());
    }

    public function test_a_secret_that_does_not_decrypt_turns_off_the_hook_instead_of_failing_the_page(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('wrongkey');
        $project = $this->projectOf($owner);
        $hook = $this->hookOf($project);
        $this->em()->getConnection()->executeStatement(
            'UPDATE github_hooks SET secret = :secret WHERE id = :id',
            ['secret' => base64_encode(random_bytes(64)), 'id' => (string) $hook->id],
        );
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#repositories [data-testid="github-hook-rotate"]'));
    }

    public function test_without_the_app_there_is_no_install_link(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('noapplink');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('#repositories [data-testid="github-app-install"]'));
    }

    public function test_with_the_app_the_owner_sees_the_install_link_and_each_installation(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('applink');
        $project = $this->projectOf($owner);
        $this->em()->persist(new GitHubInstallation($project, 11, 'acme', GitHubRepositorySelection::All));
        $suspended = new GitHubInstallation($project, 12, 'paused-org', GitHubRepositorySelection::Selected);
        $suspended->suspendedAt = new \DateTimeImmutable('-1 day');
        $this->em()->persist($suspended);
        $removed = new GitHubInstallation($project, 13, 'gone-org', GitHubRepositorySelection::Selected);
        $removed->removedAt = new \DateTimeImmutable('-1 day');
        $this->em()->persist($removed);
        $this->em()->persist(new GitHubInstallation($this->projectOf($this->signedUpUser('otherapp')), 14, 'not-mine', GitHubRepositorySelection::All));
        $this->em()->flush();
        $this->em()->clear();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook'));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $section = $crawler->filter('#repositories');
        self::assertSame('/projects/'.$project->id.'/github/install', $section->filter('[data-testid="github-app-install"]')->attr('href'));
        self::assertStringContainsString('Loupe follows the change', $section->text());
        $rows = [];
        $section->filter('[data-github-installation]')->each(static function ($row) use (&$rows): void {
            $rows[trim($row->filter('[data-testid="github-installation-account"]')->text())] = trim($row->filter('[data-testid="github-installation-state"]')->text());
        });
        self::assertSame(['acme', 'paused-org', 'gone-org'], array_keys($rows));
        self::assertSame('All repositories', $rows['acme']);
        self::assertStringStartsWith('Selected repositories · Suspended on GitHub', $rows['paused-org']);
        self::assertStringContainsString('The App was uninstalled, so its repositories left this list', $rows['gone-org']);
    }

    public function test_with_the_app_configured_an_unreadable_key_turns_off_the_hook_alone(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('apponly');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider('')));
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook'));

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        $section = $crawler->filter('#repositories');
        self::assertStringNotContainsString('The operator has not enabled forge connections', $section->text());
        self::assertStringContainsString('The operator has not enabled webhooks', $section->text());
        self::assertCount(0, $section->filter('[data-testid="github-hook-create"]'));
    }
}
