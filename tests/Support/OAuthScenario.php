<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
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
    public function createClient(string $identifier = self::CLIENT_ID, array $redirectUris = [self::REDIRECT_URI]): Client
    {
        $client = new Client(self::CLIENT_NAME, $identifier, null);
        $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $redirectUris));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope('mcp'), new Scope('site-review'), new Scope('agent'));
        $this->container->get(ClientManagerInterface::class)->save($client);

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
