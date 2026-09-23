<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubHookHealth;
use App\Module\GitHub\Repository\GitHubHookRepository;
use App\Tests\Support\RecordingAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * A hook signs its body and carries no session, so the endpoint must answer an
 * anonymous request. Every recognised outcome answers 200, because GitHub
 * retries anything else for days.
 */
final class ReceiveHookDeliveryControllerTest extends WebTestCase
{
    use GitHubDeliveryScenario;

    public function test_an_unknown_hook_key_is_not_found(): void
    {
        $client = static::createClient();

        $this->deliver($client, '/webhooks/forge/github/'.GitHubHook::newKey(), 'ping', [], 'any');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_bad_signature_is_refused_and_marks_the_hook_failing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $hook = $this->hook($this->project('failing'));

        $this->deliver($client, '/webhooks/forge/github/'.$hook->hookKey, 'ping', [], 'not-the-secret');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(AuditOutcome::Refused, $audit->record('forge.delivery_rejected')->outcome);
        $stored = $this->storedHook($hook);
        self::assertSame(GitHubHookHealth::Failing, $stored->health());
        self::assertSame('bad_signature', $stored->lastRefusedReason);
    }

    public function test_a_ping_marks_the_hook_working_and_claims_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $hook = $this->hook($this->project('ping'));

        $this->deliver($client, '/webhooks/forge/github/'.$hook->hookKey, 'ping', [
            'zen' => 'Keep it logically awesome.',
            'repository' => ['id' => 501, 'full_name' => 'acme/ping'],
        ], $hook->secret);

        self::assertResponseIsSuccessful();
        self::assertSame($hook->hookKey, $client->getRequest()->attributes->get('hookKey'), 'The rate limiter keys by this attribute.');
        self::assertSame(GitHubHookHealth::Working, $this->storedHook($hook)->health());
        self::assertNull($this->rowOf($hook->project, 501));
    }

    public function test_a_delivery_claims_the_repository_and_reaches_only_the_owning_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $hook = $this->hook($owner);
        $ownCard = $this->linkedCard($owner, 'acme/widgets', 5);
        $this->linkedCard($stranger, 'acme/widgets', 5);

        $this->deliver($client, '/webhooks/forge/github/'.$hook->hookKey, 'pull_request', $this->merged(502, 'acme/widgets', 5), $hook->secret);

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $ownCard->id], $this->outboxSubjects($owner));
        self::assertSame([], $this->outboxSubjects($stranger));
        $row = $this->rowOf($owner, 502);
        self::assertNotNull($row);
        self::assertSame(ForgeRepositorySource::Hook, $row->source);
        self::assertNotNull($row->lastAcceptedAt);
        self::assertNull($this->installationOwnerOf(502), 'A hook row makes nobody the exclusive owner.');
    }

    /** An installation of another project makes the repository exclusive on the App route alone. */
    public function test_a_repository_another_project_installed_still_feeds_the_hook_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $claimant = $this->project('claimant');
        $holder = $this->project('holder');
        $this->owned($holder, 503, 'acme/held', ForgeRepositorySource::Installation, 1);
        $hook = $this->hook($claimant);
        $claimantCard = $this->linkedCard($claimant, 'acme/held', 1);
        $this->linkedCard($holder, 'acme/held', 1);

        $this->deliver($client, '/webhooks/forge/github/'.$hook->hookKey, 'pull_request', $this->merged(503, 'acme/held', 1), $hook->secret);

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $claimantCard->id], $this->outboxSubjects($claimant));
        self::assertSame([], $this->outboxSubjects($holder));
        self::assertEquals($holder->id, $this->installationOwnerOf(503)?->project->id);
    }

    /** Ownership keys on the id, so both actions reach Board as a move of the owning project's links. */
    #[DataProvider('moves')]
    public function test_a_move_repoints_only_the_owning_project_links(string $action): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $owner = $this->project('mover');
        $stranger = $this->project('bystander');
        $this->owned($owner, 504, 'acme/old');
        $hook = $this->hook($owner);
        $ownCard = $this->linkedCard($owner, 'acme/old', 1);
        $strangerCard = $this->linkedCard($stranger, 'acme/old', 1);

        $this->deliver($client, '/webhooks/forge/github/'.$hook->hookKey, 'repository', [
            'action' => $action,
            'repository' => ['id' => 504, 'full_name' => 'neworg/new'],
        ], $hook->secret);

        self::assertResponseIsSuccessful();
        self::assertSame(['neworg/new'], $this->linkPaths($ownCard));
        self::assertSame(['acme/old'], $this->linkPaths($strangerCard));
        self::assertSame('neworg/new', $this->rowOf($owner, 504)?->path);
    }

    /** @return iterable<string, array{string}> */
    public static function moves(): iterable
    {
        yield 'renamed' => ['renamed'];
        yield 'transferred' => ['transferred'];
    }

    public function test_the_route_is_rate_limited_by_hook_key(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('webhook_forge_github_hook');

        self::assertNotNull($route);
        self::assertSame([Request::METHOD_POST], $route->getMethods());
        self::assertTrue($route->getDefault(RateLimitForgeDeliveries::MARKER));
        self::assertSame(RateLimitForgeDeliveries::KEY_BY_HOOK_KEY, $route->getDefault(RateLimitForgeDeliveries::KEYING));
    }

    private function storedHook(GitHubHook $hook): GitHubHook
    {
        $this->em()->clear();
        $hooks = self::getContainer()->get(GitHubHookRepository::class);
        self::assertInstanceOf(GitHubHookRepository::class, $hooks);

        return $hooks->findOneBy(['hookKey' => $hook->hookKey]) ?? throw new \LogicException('The hook is gone.');
    }
}
