<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Command\ConnectGitHubInstallationHandler;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class GitHubAppInstallFlowTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private const int INSTALLATION_ID = 424242;
    private const string TOKEN = 'ghu_user_token_never_logged';

    private KernelBrowser $client;

    /** @var list<array<int|string, string>> */
    private array $tokenRequests = [];

    /** @var list<string> */
    private array $apiAuthorizations = [];

    private MockResponse $tokenResponse;

    /** @var list<array<string, mixed>> */
    private array $installations = [];

    /** @var list<array<string, mixed>> */
    private array $repositories = [];

    private ?MockResponse $apiFailure = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->tokenResponse = $this->json(['access_token' => self::TOKEN, 'token_type' => 'bearer']);
        $this->installations = [$this->installation(self::INSTALLATION_ID, 'acme', 'selected')];
        $container = static::getContainer();
        $container->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook'));
        $container->set('github.oauth_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://github.com/login/oauth/access_token', $url);
            parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
            $this->tokenRequests[] = array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $body);

            return $this->tokenResponse;
        }, 'https://github.com'));
        $container->set('github.api_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $authorization = $options['normalized_headers']['authorization'][0] ?? '';
            $this->apiAuthorizations[] = \is_string($authorization) ? $authorization : '';
            if (null !== $this->apiFailure) {
                return $this->apiFailure;
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if ('/user/installations' === $path) {
                return $this->json(['total_count' => \count($this->installations), 'installations' => \array_slice($this->installations, ($page - 1) * 100, 100)]);
            }
            if ('/user/installations/'.self::INSTALLATION_ID.'/repositories' === $path) {
                return $this->json(['total_count' => \count($this->repositories), 'repositories' => \array_slice($this->repositories, ($page - 1) * 100, 100)]);
            }

            return new MockResponse('{}', ['http_code' => 404]);
        }, 'https://api.github.com'));
    }

    public function test_the_install_link_sends_the_owner_to_github_with_a_state(): void
    {
        $owner = $this->signedUpUser('install');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $state = $this->startInstall($project);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32,}$/', $state);
    }

    public function test_a_stranger_cannot_start_an_install(): void
    {
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $this->client->loginUser($stranger);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/install');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_without_the_app_the_install_link_explains_and_goes_back(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null));
        $owner = $this->signedUpUser('noapp');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/install');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('The operator has not registered a GitHub App', $this->followedText());
    }

    public function test_the_setup_url_without_a_pending_install_goes_to_the_projects(): void
    {
        $this->client->loginUser($this->signedUpUser('nopending'));
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('Start the install again', $this->followedText());
    }

    public function test_the_setup_url_needs_a_signed_in_user(): void
    {
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function test_the_setup_url_refuses_a_state_that_does_not_match(): void
    {
        $owner = $this->signedUpUser('setupstate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startInstall($project);
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID.'&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('Loupe could not confirm', $this->followedText());
        $this->client->request(Request::METHOD_GET, '/github/app/setup?installation_id='.self::INSTALLATION_ID);
        self::assertResponseRedirects('/projects');
    }

    public function test_the_setup_url_without_an_installation_explains(): void
    {
        $owner = $this->signedUpUser('setupnone');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->startInstall($project);
        $this->client->request(Request::METHOD_GET, '/github/app/setup?setup_action=request');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('GitHub did not return an installation', $this->followedText());
    }

    public function test_the_setup_url_asks_github_to_authorize_with_a_pkce_challenge(): void
    {
        $owner = $this->signedUpUser('setup');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $installState = $this->startInstall($project);
        $authorize = $this->setUpInstallation($installState);

        self::assertSame('the-client-id', $authorize['client_id']);
        self::assertSame('http://localhost/github/app/callback', $authorize['redirect_uri']);
        self::assertSame('S256', $authorize['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $authorize['code_challenge']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32,}$/', $authorize['state']);
        self::assertNotSame($installState, $authorize['state']);
    }

    public function test_the_callback_refuses_a_state_that_does_not_match(): void
    {
        $owner = $this->signedUpUser('callbackstate');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->setUpInstallation($this->startInstall($project));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=forged');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('Loupe could not confirm', $this->followedText());
        self::assertSame([], $this->tokenRequests);
        self::assertNull($this->installationRow());
    }

    public function test_the_callback_without_a_pending_install_goes_to_the_projects(): void
    {
        $this->client->loginUser($this->signedUpUser('callbacknone'));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=anything');

        self::assertResponseRedirects('/projects');
        self::assertSame([], $this->tokenRequests);
    }

    public function test_the_callback_refuses_an_installation_the_user_cannot_reach(): void
    {
        $this->installations = [$this->installation(99, 'someone-else', 'all')];
        $owner = $this->signedUpUser('spoofed');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('GitHub does not list this installation for your account', $this->followedText());
        self::assertNull($this->installationRow());
    }

    public function test_the_owner_connects_the_installation_and_its_repositories(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $others = array_map(fn (int $id): array => $this->installation($id, 'other-'.$id, 'all'), range(1, 100));
        $this->installations = [...$others, $this->installation(self::INSTALLATION_ID, 'acme', 'selected')];
        $owner = $this->signedUpUser('happy');
        $project = $this->projectOf($owner);
        $elsewhere = new ForgeRepository($this->projectOf($this->signedUpUser('elsewhere')), 'github', '303', 'acme/taken', ForgeRepositorySource::Installation, '1');
        $this->em()->persist($elsewhere);
        $this->em()->flush();
        $this->repositories = [
            ['id' => 101, 'full_name' => 'acme/one'],
            ['id' => 202, 'full_name' => 'acme/two'],
            ['id' => 303, 'full_name' => 'acme/taken'],
        ];
        $this->em()->clear();

        $this->client->loginUser($owner);
        $challenge = $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertCount(1, $this->tokenRequests);
        $exchange = $this->tokenRequests[0];
        self::assertSame('the-client-id', $exchange['client_id']);
        self::assertSame('the-client-secret', $exchange['client_secret']);
        self::assertSame('the-code', $exchange['code']);
        self::assertSame('http://localhost/github/app/callback', $exchange['redirect_uri']);
        self::assertSame($challenge, rtrim(strtr(base64_encode(hash('sha256', $exchange['code_verifier'], true)), '+/', '-_'), '='));
        self::assertSame(['Authorization: Bearer '.self::TOKEN], array_values(array_unique($this->apiAuthorizations)));

        $installation = $this->installationRow();
        self::assertNotNull($installation);
        self::assertTrue($installation->project->id?->equals($project->id));
        self::assertSame('acme', $installation->accountLogin);
        self::assertSame(GitHubRepositorySelection::Selected, $installation->repositorySelection);
        self::assertSame(['acme/one', 'acme/two'], $this->pathsOf($project));
        self::assertSame(AuditOutcome::Success, $audit->record('github.installation_connected')->outcome);

        $text = $this->followedText();
        self::assertStringContainsString('Loupe is connected to the GitHub App on acme', $text);
        self::assertStringContainsString('1 repository belongs to another project', $text);
        self::assertStringNotContainsString('elsewhere', $text);
        self::assertStringNotContainsString('may be incomplete', $text);
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/github/app/callback?code=the-code&state=anything');
        self::assertResponseRedirects('/projects');
    }

    public function test_a_known_installation_of_this_project_is_refreshed(): void
    {
        $owner = $this->signedUpUser('refresh');
        $project = $this->projectOf($owner);
        $known = new GitHubInstallation($project, self::INSTALLATION_ID, 'old-login', GitHubRepositorySelection::All);
        $known->removedAt = new \DateTimeImmutable('-1 day');
        $this->em()->persist($known);
        $this->em()->flush();
        $this->repositories = [['id' => 101, 'full_name' => 'acme/one']];
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        $installation = $this->installationRow();
        self::assertNotNull($installation);
        self::assertSame('acme', $installation->accountLogin);
        self::assertSame(GitHubRepositorySelection::Selected, $installation->repositorySelection);
        self::assertNull($installation->removedAt);
        self::assertSame(['acme/one'], $this->pathsOf($project));
    }

    /** A connect claims like a delivery, so a rename GitHub reports here reaches the card links too. */
    public function test_a_repository_renamed_since_its_last_delivery_repoints_the_card_links(): void
    {
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $owner = $this->signedUpUser('renamed');
        $project = $this->projectOf($owner);
        $column = new BoardColumn($project, 'Work', 'work', 0);
        $card = new Card($project, $column, 'Ship it', '', 1);
        $link = new CardPullRequest($card, 'https://github.com/acme/old-one/pull/4', Forge::GitHub, 'acme/old-one', 4);
        foreach ([$column, $card, $link, new ForgeRepository($project, 'github', '101', 'acme/old-one', ForgeRepositorySource::Hook)] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        $this->repositories = [['id' => 101, 'full_name' => 'acme/one']];
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertSame(['acme/one'], $this->pathsOf($project));
        $this->em()->clear();
        self::assertSame('acme/one', $this->em()->find(CardPullRequest::class, $link->id)?->repository);
    }

    public function test_a_repository_list_longer_than_ten_pages_is_reported_as_incomplete(): void
    {
        $logger = $this->recordingHandlerLogger();
        $owner = $this->signedUpUser('many');
        $project = $this->projectOf($owner);
        $this->repositories = array_map(static fn (int $id): array => ['id' => $id, 'full_name' => 'acme/repository-'.$id], range(1, 1000));
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertStringContainsString('the repository list may be incomplete', $this->followedText());
        self::assertContains('github.repository_list_truncated', array_column($logger->records, 'message'));
        self::assertTrue($this->installationRow()?->listIncomplete);
    }

    public function test_an_installation_of_another_project_is_refused_without_naming_it(): void
    {
        $other = $this->projectOf($this->signedUpUser('otherowner'));
        $this->em()->persist(new GitHubInstallation($other, self::INSTALLATION_ID, 'acme', GitHubRepositorySelection::All));
        $this->em()->flush();
        $this->repositories = [['id' => 101, 'full_name' => 'acme/one']];
        $owner = $this->signedUpUser('second');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $text = $this->followedText();
        self::assertStringContainsString('This installation belongs to another project', $text);
        self::assertStringNotContainsString($other->name, $text);
        self::assertTrue($this->installationRow()?->project->id?->equals($other->id));
        self::assertSame([], $this->pathsOf($project));
    }

    public function test_a_project_the_user_no_longer_manages_is_refused(): void
    {
        $owner = $this->signedUpUser('lost');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $newOwner = $this->signedUpUser('newcomer');

        $this->client->loginUser($owner);
        $authorize = $this->setUpInstallation($this->startInstall($project));
        $this->em()->getConnection()->executeStatement('UPDATE projects SET owner_id = ? WHERE id = ?', [(string) $newOwner->id, (string) $project->id]);
        $this->em()->clear();
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects');
        self::assertStringContainsString('You can no longer manage the connections of that project', $this->followedText());
        self::assertSame([], $this->tokenRequests);
        self::assertNull($this->installationRow());
    }

    public function test_a_person_who_cancels_on_github_goes_back_with_a_message(): void
    {
        $owner = $this->signedUpUser('cancel');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $authorize = $this->setUpInstallation($this->startInstall($project));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('GitHub did not authorize Loupe', $this->followedText());
        self::assertSame([], $this->tokenRequests);
    }

    /** @return iterable<string, array{0: ?MockResponse, 1: ?MockResponse}> */
    public static function gitHubFailures(): iterable
    {
        yield 'token endpoint down' => [new MockResponse('oops', ['http_code' => 502]), null];
        yield 'token endpoint times out' => [new MockResponse('', ['error' => 'Idle timeout reached']), null];
        yield 'token refused' => [new MockResponse('{"error":"bad_verification_code"}', ['response_headers' => ['content-type' => 'application/json']]), null];
        yield 'token body is not JSON' => [new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']]), null];
        yield 'api down' => [null, new MockResponse('{}', ['http_code' => 503])];
        yield 'api times out' => [null, new MockResponse('', ['error' => 'Idle timeout reached'])];
        yield 'api body is not JSON' => [null, new MockResponse('<html>', ['response_headers' => ['content-type' => 'text/html']])];
    }

    #[DataProvider('gitHubFailures')]
    public function test_a_github_failure_redirects_with_a_message_and_logs_no_token(?MockResponse $tokenFailure, ?MockResponse $apiFailure): void
    {
        if (null !== $tokenFailure) {
            $this->tokenResponse = $tokenFailure;
        }
        $this->apiFailure = $apiFailure;
        $logger = $this->recordingHandlerLogger();
        $owner = $this->signedUpUser('failure');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $this->client->loginUser($owner);
        $this->completeInstall($project);

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        self::assertStringContainsString('Loupe could not reach GitHub', $this->followedText());
        self::assertNull($this->installationRow());
        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'warning' === $record['level']));
        self::assertCount(1, $warnings);
        self::assertSame('github.user_api_failed', $warnings[0]['message']);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($logger->records));
        self::assertStringNotContainsString('the-client-secret', (string) json_encode($logger->records));
    }

    private function startInstall(Project $project): string
    {
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/github/install');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/apps/loupe-test/installations/new?', $location);
        $query = $this->queryOf($location);
        self::assertArrayHasKey('state', $query);

        return $query['state'];
    }

    /** @return array<int|string, string> */
    private function setUpInstallation(string $installState): array
    {
        $this->client->request(Request::METHOD_GET, '/github/app/setup?'.http_build_query(['installation_id' => self::INSTALLATION_ID, 'setup_action' => 'install', 'state' => $installState]));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);

        return $this->queryOf($location);
    }

    /** Runs the whole flow and returns the PKCE challenge GitHub was sent. */
    private function completeInstall(Project $project): string
    {
        $authorize = $this->setUpInstallation($this->startInstall($project));
        $this->client->request(Request::METHOD_GET, '/github/app/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        return $authorize['code_challenge'];
    }

    /** @return array<int|string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $query);
    }

    private function followedText(): string
    {
        return $this->client->followRedirect()->filter('body')->text();
    }

    /** @return array<string, mixed> */
    private function installation(int $id, string $login, string $selection): array
    {
        return ['id' => $id, 'account' => ['login' => $login], 'repository_selection' => $selection];
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function installationRow(): ?GitHubInstallation
    {
        $this->em()->clear();
        $installations = static::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);

        return $installations->findOneByInstallationId(self::INSTALLATION_ID);
    }

    /** @return list<string> */
    private function pathsOf(Project $project): array
    {
        $this->em()->clear();
        $repositories = static::getContainer()->get(ForgeRepositoryRepository::class);
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        $paths = array_map(static fn (ForgeRepository $repository): string => $repository->path, $repositories->findBy(['project' => $project->id]));
        sort($paths);

        return $paths;
    }

    /** Rebuilds the handler around a recording logger, so a test reads every line it logs. */
    private function recordingHandlerLogger(): RecordingLogger
    {
        $container = static::getContainer();
        $logger = new RecordingLogger();
        $get = static function (string $id) use ($container): object {
            $service = $container->get($id);
            self::assertIsObject($service);

            return $service;
        };
        $handler = new ConnectGitHubInstallationHandler(
            self::narrow($get(ProjectRepository::class), ProjectRepository::class),
            self::narrow($get('security.authorization_checker'), AuthorizationCheckerInterface::class),
            self::narrow($get(GitHubUserApi::class), GitHubUserApi::class),
            self::narrow($get(GitHubInstallationRepository::class), GitHubInstallationRepository::class),
            self::narrow($get(DeliveryAnnouncer::class), DeliveryAnnouncer::class),
            self::narrow($get(EntityManagerInterface::class), EntityManagerInterface::class),
            self::narrow($get(Auditor::class), Auditor::class),
            $logger,
        );
        $container->set(ConnectGitHubInstallationHandler::class, $handler);

        return $logger;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function narrow(object $service, string $class): object
    {
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
