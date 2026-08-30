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
        return new User(fullName: 'Alice', email: 'alice@example.com');
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

    public function test_issue_records_the_last_four_characters_as_the_tail(): void
    {
        [$token, $raw] = ApiToken::issue($this->user(), 'CI agent', ApiTokenScope::Mcp);

        self::assertSame(substr($raw, -4), $token->tokenTail);
        self::assertSame(4, \strlen((string) $token->tokenTail));
        self::assertStringEndsWith((string) $token->tokenTail, $raw);
    }

    /** The identifier is Doctrine's, so a token that was never flushed has none to give. */
    public function test_an_unflushed_token_reports_no_audit_identifier(): void
    {
        [$token] = ApiToken::issue($this->user(), 'CI agent', ApiTokenScope::Mcp);

        self::assertNull($token->auditIdentifier());
    }
}
