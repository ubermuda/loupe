<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Module\GitHub\Service\HookSecretKey;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class CreateGitHubHookControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_creates_a_hook_and_sees_the_secret_once(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('create');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $this->em()->clear();
        $hooks = static::getContainer()->get(GitHubHookRepository::class);
        self::assertInstanceOf(GitHubHookRepository::class, $hooks);
        $hook = $hooks->findOneBy(['project' => $project->id]);
        self::assertNotNull($hook);
        self::assertSame(AuditOutcome::Success, $audit->record('github.hook_created')->outcome);

        $crawler = $client->followRedirect();
        self::assertSame($hook->secret, trim($crawler->filter('#repositories [data-testid="github-hook-secret"]')->text()));
        self::assertStringEndsWith('/webhooks/forge/github/'.$hook->hookKey, trim($crawler->filter('#repositories [data-testid="github-hook-url"]')->text()));

        $crawler = $this->connectPage($client, $project);
        self::assertCount(0, $crawler->filter('#repositories [data-testid="github-hook-secret"]'));
        self::assertStringNotContainsString($hook->secret, (string) $client->getResponse()->getContent());
    }

    public function test_a_second_hook_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('twice');
        $project = $this->projectOf($owner);
        $hook = $this->hookOf($project);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $this->em()->clear();
        $hooks = static::getContainer()->get(GitHubHookRepository::class);
        self::assertInstanceOf(GitHubHookRepository::class, $hooks);
        self::assertSame($hook->hookKey, $hooks->findOneBy(['project' => $project->id])?->hookKey);
    }

    /**
     * A double click sends two creates. Both miss the read, and the second
     * meets the one-hook-per-project key. The listener plays the first click.
     */
    public function test_a_hook_created_between_the_check_and_the_write_is_refused_with_a_message(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('race');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        $competitor = new class {
            public bool $fired = false;

            public function preFlush(PreFlushEventArgs $args): void
            {
                if ($this->fired) {
                    return;
                }
                $this->fired = true;
                $em = $args->getObjectManager();
                foreach ($em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof GitHubHook) {
                        $em->persist(new GitHubHook($entity->project, GitHubHook::newKey(), GitHubHook::newSecret()));
                    }
                }
            }
        };
        $this->em()->getEventManager()->addEventListener(Events::preFlush, $competitor);

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook');

        self::assertTrue($competitor->fired);
        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This project already has a webhook', $crawler->filter('body')->text());
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->postAction($client, '/projects/'.$project->id.'/github/hook');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $hooks = static::getContainer()->get(GitHubHookRepository::class);
        self::assertInstanceOf(GitHubHookRepository::class, $hooks);
        self::assertNull($hooks->findOneBy(['project' => $project->id]));
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
        $this->postAction($client, '/projects/'.$project->id.'/github/hook');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The operator has not enabled webhooks', $crawler->filter('body')->text());
        $hooks = static::getContainer()->get(GitHubHookRepository::class);
        self::assertInstanceOf(GitHubHookRepository::class, $hooks);
        self::assertNull($hooks->findOneBy(['project' => $project->id]));
    }
}
