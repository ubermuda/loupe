<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\ApiTokenAuthenticator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class ApiTokenAuthenticatorTest extends TestCase
{
    private const string RAW_TOKEN = 'lp_secret_raw_token_value_should_never_be_logged';

    private ApiTokenRepository&Stub $apiTokens;
    private LoggerInterface&MockObject $logger;
    private ApiTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->apiTokens = $this->createStub(ApiTokenRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->authenticator = new ApiTokenAuthenticator($this->apiTokens, $this->logger);
    }

    public function test_authentication_failure_is_logged_with_request_context(): void
    {
        /** @var array<string, mixed> $context */
        $context = [];
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'account.api_token.authentication_failed',
                $this->callback(static function (array $actual) use (&$context): bool {
                    $context = $actual;

                    return true;
                }),
            );

        $response = $this->authenticator->onAuthenticationFailure(
            $this->bearerRequest('/mcp'),
            new AuthenticationException('Invalid API token.'),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('/mcp', $context['path'] ?? null);
        self::assertSame('203.0.113.9', $context['ip'] ?? null);
        self::assertSame('Invalid API token.', $context['reason'] ?? null);
    }

    public function test_authentication_failure_log_never_carries_the_presented_token(): void
    {
        /** @var array<string, mixed> $context */
        $context = [];
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                self::anything(),
                $this->callback(static function (array $actual) use (&$context): bool {
                    $context = $actual;

                    return true;
                }),
            );

        $this->authenticator->onAuthenticationFailure(
            $this->bearerRequest('/api/site-review/comments'),
            new AuthenticationException('Invalid API token.'),
        );

        // Guard: without it an empty context would satisfy the assertion below.
        self::assertNotEmpty($context);
        self::assertStringNotContainsString(
            self::RAW_TOKEN,
            json_encode($context, JSON_THROW_ON_ERROR),
        );
    }

    private function bearerRequest(string $path): Request
    {
        return Request::create($path, Request::METHOD_POST, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::RAW_TOKEN,
            'REMOTE_ADDR' => '203.0.113.9',
        ]);
    }
}
