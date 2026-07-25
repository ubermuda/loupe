<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Service\ApiTokenExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ApiTokenExporterTest extends TestCase
{
    public function test_exports_metadata_and_never_the_token_hash(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        [$token] = ApiToken::issue($user, 'My agent', ApiTokenScope::Mcp);

        /** @var ApiTokenRepository&Stub $repo */
        $repo = $this->createStub(ApiTokenRepository::class);
        $repo->method('findBy')->willReturn([$token]);

        $rows = new ApiTokenExporter($repo)->export($user);

        self::assertCount(1, $rows);
        self::assertSame('My agent', $rows[0]['label']);
        self::assertSame('mcp', $rows[0]['scope']);
        self::assertArrayNotHasKey('tokenHash', $rows[0]);
        $encoded = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token->tokenHash, $encoded);
    }
}
