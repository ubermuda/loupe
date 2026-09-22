<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Audit\EventListener\SetConsoleAuditChannelListener;
use App\Audit\LoupeAuditActorProvider;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Entity\GrantedCredential;
use App\Module\OAuth\Repository\GrantedCredentialRepository;
use App\Module\OAuth\Scope\ApiScope;
use App\Security\AuthenticatedCredential;
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
        $this->tokenStorage->setToken($this->securityTokenWithoutCredential());

        $actor = $this->provider()->currentActor();

        self::assertSame(AuditChannel::Session->value, $actor->channel);
        self::assertSame($this->user, $actor->actor);
        self::assertNull($actor->credential);
    }

    public function test_an_mcp_credential_is_its_own_channel(): void
    {
        $record = $this->record();
        $this->tokenStorage->setToken($this->securityTokenWithCredential(ApiScope::Mcp));

        $actor = $this->provider($record)->currentActor();

        self::assertSame(AuditChannel::Mcp->value, $actor->channel);
        self::assertSame($this->user, $actor->actor);
        self::assertSame($record, $actor->credential);
    }

    public function test_a_site_review_credential_is_the_widget_channel(): void
    {
        $record = $this->record();
        $this->tokenStorage->setToken($this->securityTokenWithCredential(ApiScope::SiteReview));

        $actor = $this->provider($record)->currentActor();

        self::assertSame(AuditChannel::Widget->value, $actor->channel);
        self::assertSame($record, $actor->credential);
    }

    public function test_an_agent_credential_is_the_agent_channel(): void
    {
        $this->tokenStorage->setToken($this->securityTokenWithCredential(ApiScope::Agent));

        self::assertSame(AuditChannel::Agent->value, $this->provider()->currentActor()->channel);
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
        $this->tokenStorage->setToken($this->securityTokenWithoutCredential());
        $this->auditContext->channel = AuditChannel::Webhook;

        self::assertSame(AuditChannel::Webhook->value, $this->provider()->currentActor()->channel);
    }

    public function test_the_ambient_context_travels_with_the_actor(): void
    {
        $this->auditContext->ambientContext = ['async' => true];

        self::assertSame(['async' => true], $this->provider()->currentActor()->context);
    }

    private function provider(?GrantedCredential $record = null): LoupeAuditActorProvider
    {
        $grantedCredentials = $this->createStub(GrantedCredentialRepository::class);
        $grantedCredentials->method('findOrCreate')->willReturn($record ?? $this->record());

        return new LoupeAuditActorProvider(
            $this->tokenStorage,
            $grantedCredentials,
            $this->auditContext,
        );
    }

    private function record(): GrantedCredential
    {
        return new GrantedCredential('oauth:test-client:'.Uuid::v7().':-', $this->user);
    }

    private function securityTokenWithCredential(ApiScope $scope): TokenInterface&Stub
    {
        $credential = new AuthenticatedCredential('oauth:test-client:'.Uuid::v7().':-', [$scope->role()]);

        $securityToken = $this->securityToken();
        $securityToken->method('hasAttribute')
            ->willReturnCallback(static fn (string $name): bool => AuthenticatedCredential::ATTRIBUTE === $name);
        $securityToken->method('getAttribute')->willReturn($credential);

        return $securityToken;
    }

    private function securityTokenWithoutCredential(): TokenInterface&Stub
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
