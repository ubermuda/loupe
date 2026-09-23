<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Ubermuda\AuditBundle\AuditOutcome;

/** The App route verifies with the App's own secret, and acts only for an installation Loupe knows. */
final class ReceiveAppDeliveryControllerTest extends WebTestCase
{
    use GitHubDeliveryScenario;

    private const string PATH = '/webhooks/forge/github';
    private const string SECRET = 'github_app_webhook_test';

    public function test_a_bad_signature_is_refused_and_audited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(601, 'acme/a', 1), 'not-the-secret');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(AuditOutcome::Refused, $audit->record('forge.delivery_rejected')->outcome);
        self::assertTrue($client->getRequest()->attributes->get(RateLimitForgeDeliveries::MARKER), 'The rate limiter reads the route default from the request.');
    }

    /** A repository hook that someone signed with the App secret still names no installation. */
    public function test_a_delivery_without_an_installation_is_dropped(): void
    {
        $client = static::createClient();

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(602, 'acme/b', 1), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(602));
    }

    public function test_a_delivery_for_an_unknown_installation_is_dropped(): void
    {
        $client = static::createClient();

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(603, 'acme/c', 1, ['installation' => ['id' => 9_000_603]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(603));
    }

    public function test_an_installation_that_reaches_all_repositories_claims_on_first_delivery(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('all');
        $this->installation($project, 9_000_604, GitHubRepositorySelection::All);
        $card = $this->linkedCard($project, 'acme/d', 3);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(604, 'acme/d', 3, ['installation' => ['id' => 9_000_604]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertEquals($project->id, $this->installationOwnerOf(604)?->project->id);
        self::assertSame([(string) $card->id], $this->outboxSubjects($project));
    }

    public function test_an_installation_with_selected_repositories_never_claims_on_a_delivery(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('selected');
        $this->installation($project, 9_000_605);
        $this->linkedCard($project, 'acme/e', 3);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(605, 'acme/e', 3, ['installation' => ['id' => 9_000_605]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(605));
        self::assertSame([], $this->outboxSubjects($project));
    }

    public function test_a_repository_the_installation_project_owns_is_announced(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('owned');
        $this->installation($project, 9_000_606);
        $this->owned($project, 606, 'acme/f', ForgeRepositorySource::Installation, 9_000_606);
        $card = $this->linkedCard($project, 'acme/f', 3);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(606, 'acme/f', 3, ['installation' => ['id' => 9_000_606]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $card->id], $this->outboxSubjects($project));
    }

    public function test_a_repository_another_installation_owns_is_dropped(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('installed');
        $holder = $this->project('holder');
        $this->installation($project, 9_000_607, GitHubRepositorySelection::All);
        $this->owned($holder, 607, 'acme/g', ForgeRepositorySource::Installation, 1);
        $this->linkedCard($project, 'acme/g', 3);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(607, 'acme/g', 3, ['installation' => ['id' => 9_000_607]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->outboxSubjects($project));
        self::assertEquals($holder->id, $this->installationOwnerOf(607)?->project->id);
    }

    public function test_repositories_added_are_claimed_and_repositories_removed_are_released(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('changed');
        $holder = $this->project('holder');
        $this->installation($project, 9_000_608);
        $this->owned($project, 6082, 'acme/leaving', ForgeRepositorySource::Installation, 9_000_608);
        $this->owned($holder, 6083, 'acme/theirs', ForgeRepositorySource::Installation, 1);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_608],
            'repository_selection' => 'all',
            'repositories_added' => [['id' => 6081, 'full_name' => 'acme/arriving'], ['id' => 6083, 'full_name' => 'acme/theirs']],
            'repositories_removed' => [['id' => 6082, 'full_name' => 'acme/leaving']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertEquals($project->id, $this->installationOwnerOf(6081)?->project->id);
        self::assertNull($this->installationOwnerOf(6082));
        self::assertEquals($holder->id, $this->installationOwnerOf(6083)?->project->id);
        self::assertSame(GitHubRepositorySelection::All, $this->storedInstallation(9_000_608)->repositorySelection);
    }

    public function test_a_suspended_installation_drops_deliveries_until_it_is_unsuspended(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('suspended');
        $this->installation($project, 9_000_609, GitHubRepositorySelection::All);
        $card = $this->linkedCard($project, 'acme/h', 3);
        $installation = ['installation' => ['id' => 9_000_609]];

        $this->deliver($client, self::PATH, 'installation', ['action' => 'suspend', ...$installation], self::SECRET);
        self::assertNotNull($this->storedInstallation(9_000_609)->suspendedAt);
        $this->deliver($client, self::PATH, 'pull_request', $this->merged(609, 'acme/h', 3, $installation), self::SECRET);
        self::assertSame([], $this->outboxSubjects($project));

        $this->deliver($client, self::PATH, 'installation', ['action' => 'unsuspend', ...$installation], self::SECRET);
        self::assertNull($this->storedInstallation(9_000_609)->suspendedAt);
        $this->deliver($client, self::PATH, 'pull_request', $this->merged(609, 'acme/h', 3, $installation), self::SECRET);
        self::assertSame([(string) $card->id], $this->outboxSubjects($project));
    }

    /** The payload may omit the list, so the release reads the stored installation reference. */
    public function test_a_deleted_installation_keeps_its_row_and_releases_its_repositories(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('deleted');
        $this->installation($project, 9_000_610);
        $this->owned($project, 610, 'acme/i', ForgeRepositorySource::Installation, 9_000_610);
        $this->owned($project, 6101, 'acme/unlisted', ForgeRepositorySource::Installation, 9_000_610);
        $this->owned($project, 6102, 'acme/hooked');

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'deleted',
            'installation' => ['id' => 9_000_610],
            'repositories' => [['id' => 610, 'full_name' => 'acme/i']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->storedInstallation(9_000_610)->removedAt);
        self::assertNull($this->rowOf($project, 610));
        self::assertNull($this->rowOf($project, 6101));
        self::assertNotNull($this->rowOf($project, 6102), 'A hook row outlives the installation.');
    }

    public function test_a_suspended_installation_claims_no_added_repository(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('suspended-add');
        $installation = $this->installation($project, 9_000_612);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_612],
            'repositories_added' => [['id' => 612, 'full_name' => 'acme/k']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rowOf($project, 612));
    }

    /** A rename that arrives with a selection change repoints the links like a delivery does. */
    public function test_an_added_repository_under_a_new_path_repoints_the_project_links(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->enableBoard();
        $project = $this->project('renamed');
        $this->installation($project, 9_000_613);
        $this->owned($project, 613, 'acme/old', ForgeRepositorySource::Installation, 9_000_613);
        $card = $this->linkedCard($project, 'acme/old', 1);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_613],
            'repositories_added' => [['id' => 613, 'full_name' => 'acme/new']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame(['acme/new'], $this->linkPaths($card));
    }

    public function test_a_created_event_for_a_known_installation_claims_its_repositories(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('created');
        $this->installation($project, 9_000_611);

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'created',
            'installation' => ['id' => 9_000_611, 'repository_selection' => 'selected'],
            'repositories' => [['id' => 611, 'full_name' => 'acme/j']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertEquals($project->id, $this->installationOwnerOf(611)?->project->id);
    }

    public function test_the_route_is_rate_limited_by_client_address(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('webhook_forge_github');

        self::assertNotNull($route);
        self::assertSame([Request::METHOD_POST], $route->getMethods());
        self::assertTrue($route->getDefault(RateLimitForgeDeliveries::MARKER));
        self::assertSame(RateLimitForgeDeliveries::KEY_BY_ADDRESS, $route->getDefault(RateLimitForgeDeliveries::KEYING));
    }

    private function storedInstallation(int $installationId): GitHubInstallation
    {
        $this->em()->clear();
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);

        return $installations->findOneByInstallationId($installationId) ?? throw new \LogicException('The installation is gone.');
    }
}
