<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Service\HookSecretKey;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class RotateGitHubHookSecretControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_gets_a_new_secret_on_the_same_url(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('rotate');
        $project = $this->projectOf($owner);
        $hook = $this->hookOf($project);
        $oldSecret = $hook->secret;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook/secret');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $this->em()->clear();
        $stored = $this->em()->find(GitHubHook::class, $hook->id);
        self::assertNotNull($stored);
        self::assertNotSame($oldSecret, $stored->secret);
        self::assertSame($hook->hookKey, $stored->hookKey);
        self::assertSame(AuditOutcome::Success, $audit->record('github.hook_secret_rotated')->outcome);

        $crawler = $client->followRedirect();
        self::assertSame($stored->secret, trim($crawler->filter('#repositories [data-testid="github-hook-secret"]')->text()));
    }

    public function test_without_a_hook_nothing_rotates(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nohook');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook/secret');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $crawler = $client->followRedirect();
        self::assertCount(0, $crawler->filter('#repositories [data-testid="github-hook-secret"]'));
    }

    public function test_a_stranger_is_forbidden_and_the_secret_stays(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $hook = $this->hookOf($project);
        $secret = $hook->secret;
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook/secret');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em()->clear();
        self::assertSame($secret, $this->em()->find(GitHubHook::class, $hook->id)?->secret);
    }

    public function test_an_unreadable_key_refuses_with_a_message_instead_of_an_error(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->signedUpUser('nokey');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider('')));

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook/secret');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The operator has not enabled webhooks', $crawler->filter('body')->text());
    }
}
