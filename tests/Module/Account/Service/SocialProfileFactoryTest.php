<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Service\GithubPrimaryEmailFetcher;
use App\Module\Account\Service\SocialProfileFactory;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SocialProfileFactoryTest extends TestCase
{
    public function test_maps_a_google_resource_owner(): void
    {
        $profile = $this->factory()->fromResourceOwner(
            SocialProvider::Google,
            $this->owner('google-sub-123', ['email' => 'g@example.com', 'email_verified' => true, 'name' => 'Grace Hopper']),
            'token',
        );

        self::assertSame(SocialProvider::Google, $profile->provider);
        self::assertSame('google-sub-123', $profile->providerUserId);
        self::assertSame('g@example.com', $profile->email);
        self::assertSame('Grace Hopper', $profile->fullName);
        self::assertTrue($profile->emailVerified);
    }

    public function test_google_email_verified_true_string_is_treated_as_verified(): void
    {
        // Google sometimes serialises the claim as the string "true" rather than a bool.
        $profile = $this->factory()->fromResourceOwner(
            SocialProvider::Google,
            $this->owner('google-sub-str', ['email' => 'g@example.com', 'email_verified' => 'true']),
            'token',
        );

        self::assertTrue($profile->emailVerified);
    }

    public function test_google_email_verified_false_is_unverified(): void
    {
        $profile = $this->factory()->fromResourceOwner(
            SocialProvider::Google,
            $this->owner('google-sub-unv', ['email' => 'g@example.com', 'email_verified' => false]),
            'token',
        );

        self::assertSame('g@example.com', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function test_google_missing_email_verified_claim_defaults_to_unverified(): void
    {
        $profile = $this->factory()->fromResourceOwner(
            SocialProvider::Google,
            $this->owner('google-sub-missing', ['email' => 'g@example.com']),
            'token',
        );

        self::assertFalse($profile->emailVerified);
    }

    public function test_google_empty_strings_become_null(): void
    {
        $profile = $this->factory()->fromResourceOwner(
            SocialProvider::Google,
            $this->owner('google-sub-0', ['email' => '', 'name' => '', 'email_verified' => true]),
            'token',
        );

        self::assertNull($profile->email);
        self::assertNull($profile->fullName);
    }

    public function test_github_uses_the_verified_primary_email_over_the_public_profile_email(): void
    {
        $profile = $this->factory(new JsonMockResponse([
            ['email' => 'octo@example.com', 'primary' => true, 'verified' => true],
        ]))->fromResourceOwner(
            SocialProvider::Github,
            $this->owner(4242, ['email' => 'public@example.com', 'name' => 'Octo Cat', 'login' => 'octocat']),
            'token',
        );

        self::assertSame(SocialProvider::Github, $profile->provider);
        self::assertSame('4242', $profile->providerUserId);
        self::assertSame('octo@example.com', $profile->email);
        self::assertSame('Octo Cat', $profile->fullName);
        self::assertTrue($profile->emailVerified);
    }

    public function test_github_unverified_primary_email_is_kept_but_not_trusted(): void
    {
        $profile = $this->factory(new JsonMockResponse([
            ['email' => 'octo@example.com', 'primary' => true, 'verified' => false],
        ]))->fromResourceOwner(
            SocialProvider::Github,
            $this->owner('gh-1', ['login' => 'octocat']),
            'token',
        );

        self::assertSame('octo@example.com', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function test_github_email_fetch_failure_falls_back_to_the_public_email_unverified(): void
    {
        $profile = $this->factory(new MockResponse('', ['http_code' => 500]))->fromResourceOwner(
            SocialProvider::Github,
            $this->owner('gh-2', ['email' => 'public@example.com', 'login' => 'octocat']),
            'token',
        );

        self::assertSame('public@example.com', $profile->email);
        self::assertFalse($profile->emailVerified);
    }

    public function test_github_display_name_falls_back_to_the_login(): void
    {
        $profile = $this->factory(new JsonMockResponse([
            ['email' => 'octo@example.com', 'primary' => true, 'verified' => true],
        ]))->fromResourceOwner(
            SocialProvider::Github,
            $this->owner('gh-3', ['name' => null, 'login' => 'octocat']),
            'token',
        );

        self::assertSame('octocat', $profile->fullName);
    }

    public function test_github_without_any_email_is_unverified_and_null(): void
    {
        $profile = $this->factory(new JsonMockResponse([]))->fromResourceOwner(
            SocialProvider::Github,
            $this->owner('gh-4', ['login' => 'octocat']),
            'token',
        );

        self::assertNull($profile->email);
        self::assertFalse($profile->emailVerified);
    }

    private function factory(?ResponseInterface $githubEmailsResponse = null): SocialProfileFactory
    {
        return new SocialProfileFactory(
            new GithubPrimaryEmailFetcher(new MockHttpClient($githubEmailsResponse ?? new JsonMockResponse([]))),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function owner(mixed $id, array $data): ResourceOwnerInterface
    {
        return new readonly class($id, $data) implements ResourceOwnerInterface {
            /** @param array<string, mixed> $data */
            public function __construct(
                private mixed $id,
                private array $data,
            ) {
            }

            public function getId(): mixed
            {
                return $this->id;
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return $this->data;
            }
        };
    }
}
