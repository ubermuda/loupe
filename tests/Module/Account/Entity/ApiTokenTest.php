<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Entity;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
{
    private function user(): User
    {
        return new User(username: 'alice', fullName: 'Alice', email: 'alice@example.com');
    }

    public function test_issue_returns_raw_token_and_stores_only_hash(): void
    {
        [$token, $raw] = ApiToken::issue($this->user(), 'CI agent', ApiTokenScope::Mcp);

        self::assertNotSame('', $raw);
        self::assertSame('CI agent', $token->label);
        self::assertSame(ApiTokenScope::Mcp, $token->scope);
        self::assertSame(hash('sha256', $raw), $token->tokenHash);
        self::assertTrue($token->matches($raw));
        self::assertFalse($token->matches('wrong'));
    }
}
