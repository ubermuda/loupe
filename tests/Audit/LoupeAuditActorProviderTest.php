<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Audit\EventListener\SetConsoleAuditChannelListener;
use App\Audit\LoupeAuditActorProvider;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * One test per channel: getting a channel wrong changes nothing else, so a
 * shared case would hide which of them regressed.
 */
final class LoupeAuditActorProviderTest extends TestCase
{
    private TokenStorage $tokenStorage;
    private AuditContext $auditContext;
    private User $user;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->auditContext = new AuditContext();
        $this->user = new User('Riley Chen', 'riley@example.com', 'x');
    }

    public function test_a_session_request_is_attributed_to_the_signed_in_user(): void
    {
        $this->tokenStorage->setToken($this->securityTokenWithoutApiToken());

        $actor = $this->provider()->currentActor();

        self::assertSame(AuditChannel::Session->value, $actor->channel);
        self::assertSame($this->user, $actor->actor);
        self::assertSame('Riley Chen', $actor->actorLabel);
        self::assertNull($actor->credential);
    }

    public function test_an_mcp_token_is_its_own_channel(): void
    {
        $apiToken = $this->apiTokenWithScope(ApiTokenScope::Mcp);
        $this->tokenStorage->setToken($this->securityTokenWithApiToken());

        $actor = $this->provider($apiToken)->currentActor();

        self::assertSame(AuditChannel::Mcp->value, $actor->channel);
        self::assertSame($this->user, $actor->actor);
        self::assertSame($apiToken, $actor->credential);
    }

    public function test_a_site_review_token_is_the_widget_channel(): void
    {
        $apiToken = $this->apiTokenWithScope(ApiTokenScope::SiteReview);
        $this->tokenStorage->setToken($this->securityTokenWithApiToken());

        $actor = $this->provider($apiToken)->currentActor();

        self::assertSame(AuditChannel::Widget->value, $actor->channel);
        self::assertSame($apiToken, $actor->credential);
    }

    /**
     * Registration, OAuth login, password reset and the install flow are all
     * anonymous writes. Detection reports what the security token says and
     * nothing more, so an unattributed record beats guessing `webhook` at them.
     */
    public function test_an_anonymous_request_is_unattributed_rather_than_guessed_at(): void
    {
        $actor = $this->provider()->currentActor();

        self::assertSame(AuditChannel::System->value, $actor->channel);
        self::assertNull($actor->actor);
        self::assertNull($actor->actorLabel);
        self::assertNull($actor->credential);
    }

    public function test_a_console_command_is_the_console_channel(): void
    {
        $provider = $this->provider();
        self::assertSame(AuditChannel::System->value, $provider->currentActor()->channel);

        (new SetConsoleAuditChannelListener($this->auditContext))(
            new ConsoleCommandEvent(new Command('app:whatever'), new ArrayInput([]), new NullOutput()),
        );

        self::assertSame(AuditChannel::Console->value, $provider->currentActor()->channel);
    }

    public function test_a_cron_tick_is_the_cron_channel(): void
    {
        $this->auditContext->channel = AuditChannel::Cron;

        self::assertSame(AuditChannel::Cron->value, $this->provider()->currentActor()->channel);
    }

    public function test_a_declared_channel_beats_a_detected_one(): void
    {
        $this->tokenStorage->setToken($this->securityTokenWithoutApiToken());
        $this->auditContext->channel = AuditChannel::Webhook;

        self::assertSame(AuditChannel::Webhook->value, $this->provider()->currentActor()->channel);
    }

    public function test_the_ambient_context_travels_with_the_actor(): void
    {
        $this->auditContext->ambientContext = ['async' => true];

        self::assertSame(['async' => true], $this->provider()->currentActor()->context);
    }

    private function provider(?ApiToken $apiToken = null): LoupeAuditActorProvider
    {
        $apiTokens = $this->createStub(ApiTokenRepository::class);
        $apiTokens->method('find')->willReturn($apiToken);

        return new LoupeAuditActorProvider(
            $this->tokenStorage,
            new AuthenticatedApiTokenResolver($this->tokenStorage, $apiTokens),
            $this->auditContext,
        );
    }

    private function apiTokenWithScope(ApiTokenScope $scope): ApiToken
    {
        return ApiToken::issue($this->user, 'Test token', $scope)[0];
    }

    private function securityTokenWithApiToken(): TokenInterface&Stub
    {
        $securityToken = $this->securityToken();
        $securityToken->method('hasAttribute')
            ->willReturnCallback(static fn (string $name): bool => ApiTokenAuthenticator::API_TOKEN_ID_ATTR === $name);
        $securityToken->method('getAttribute')->willReturn((string) Uuid::v7());

        return $securityToken;
    }

    private function securityTokenWithoutApiToken(): TokenInterface&Stub
    {
        $securityToken = $this->securityToken();
        $securityToken->method('hasAttribute')->willReturn(false);

        return $securityToken;
    }

    private function securityToken(): TokenInterface&Stub
    {
        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('getUser')->willReturn($this->user);

        return $securityToken;
    }
}
