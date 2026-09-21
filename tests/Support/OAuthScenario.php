<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the pieces of an OAuth authorization code flow: a public client, a
 * verified user with a project, and a PKCE pair. Test clients are seeded here.
 * The one migration-registered client is loupe-cli.
 */
final readonly class OAuthScenario
{
    public const string CLIENT_ID = 'test-client';
    public const string CLIENT_NAME = 'Test Client';
    public const string REDIRECT_URI = 'https://client.example/callback';

    public string $codeVerifier;
    public string $codeChallenge;

    public function __construct(
        private ContainerInterface $container,
    ) {
        $this->codeVerifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->codeChallenge = self::challengeFor($this->codeVerifier);
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param non-empty-string       $identifier
     * @param list<non-empty-string> $redirectUris
     */
    public function createClient(string $identifier = self::CLIENT_ID, array $redirectUris = [self::REDIRECT_URI]): ClientInterface
    {
        $manager = $this->container->get(ClientManagerInterface::class);
        // A test that takes two credentials seeds the row twice, and the second
        // save is an insert of an identifier the identity map already holds.
        $client = $manager->find($identifier) ?? new Client(self::CLIENT_NAME, $identifier, null);
        $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $redirectUris));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('mcp'), new Scope('site-review'), new Scope('agent'), new Scope('projects'));
        $manager->save($client);

        return $client;
    }

    /** @param non-empty-string $email */
    public function createUser(string $email): User
    {
        $em = $this->container->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, $this->container);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function createProject(User $owner, string $name): Project
    {
        $em = $this->container->get(EntityManagerInterface::class);
        $project = new Project($owner, $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    /** @param array<string, string> $overrides */
    public function authorizeUrl(string $scope = 'mcp', array $overrides = []): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => $scope,
            'state' => 'state-123',
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            ...$overrides,
        ]);
    }

    /**
     * The query of a redirect to the client.
     *
     * @return array<array-key, string>
     */
    public static function redirectQuery(string $location): array
    {
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return array_filter($query, \is_string(...));
    }

    /**
     * Runs consent and the code exchange for a project-bound scope.
     *
     * @param array<string, string> $authorizeOverrides
     * @param array<string, string> $tokenParameters
     *
     * @return array{access_token: string, refresh_token: string}
     */
    public function grantTokens(KernelBrowser $browser, User $user, Project $project, array $authorizeOverrides = [], array $tokenParameters = []): array
    {
        $browser->loginUser($user);
        $crawler = $browser->request(Request::METHOD_GET, $this->authorizeUrl('mcp', $authorizeOverrides));
        $browser->submit($crawler->selectButton('consent_form_approve')->form([
            'consent_form[project]' => (string) $project->id,
        ]));
        $query = self::redirectQuery((string) $browser->getResponse()->headers->get('Location'));
        $browser->restart();
        $tokens = self::postToken($browser, [
            'grant_type' => 'authorization_code',
            'client_id' => $authorizeOverrides['client_id'] ?? self::CLIENT_ID,
            'redirect_uri' => $authorizeOverrides['redirect_uri'] ?? self::REDIRECT_URI,
            'code' => $query['code'] ?? '',
            'code_verifier' => $this->codeVerifier,
            ...$tokenParameters,
        ]);
        if (!\is_string($tokens['access_token'] ?? null) || !\is_string($tokens['refresh_token'] ?? null)) {
            throw new \LogicException('the token endpoint issued no tokens: '.json_encode($tokens));
        }

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']];
    }

    /**
     * One access token for any scope set, through consent and the code exchange.
     *
     * The picker is on the consent form only where a scope needs one project,
     * so a grant over every project of the owner passes no project at all.
     *
     * It clears the cookie jar rather than restarting the browser, so the
     * caller keeps the entity manager it already holds. A restart reboots the
     * kernel, and every entity the test loaded before it is then detached.
     */
    public function accessTokenFor(KernelBrowser $browser, User $user, string $scope, ?Project $project = null): string
    {
        $browser->loginUser($user);
        $crawler = $browser->request(Request::METHOD_GET, $this->authorizeUrl($scope));
        $form = $crawler->selectButton('consent_form_approve')->form();
        if (null !== $project) {
            $form['consent_form[project]'] = (string) $project->id;
        }
        $browser->submit($form);
        $query = self::redirectQuery((string) $browser->getResponse()->headers->get('Location'));
        $browser->getCookieJar()->clear();
        $tokens = self::postToken($browser, [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $query['code'] ?? '',
            'code_verifier' => $this->codeVerifier,
        ]);

        return \is_string($tokens['access_token'] ?? null)
            ? $tokens['access_token']
            : throw new \LogicException('the token endpoint issued no access token: '.json_encode($tokens));
    }

    /**
     * The claims of a JWT, read without a signature check.
     *
     * @return array<string, mixed>
     */
    public static function claimsOf(string $jwt): array
    {
        $payload = explode('.', $jwt)[1] ?? '';
        $decoded = json_decode(base64_decode(strtr($payload, '-_', '+/'), true) ?: '', true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    public static function postToken(KernelBrowser $browser, array $parameters): array
    {
        $browser->request(Request::METHOD_POST, '/oauth/token', $parameters, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
