<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\RevokeApiTokenCommand;
use App\Module\Account\Command\RevokeApiTokenHandler;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RevokeApiTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RevokeApiTokenHandler $handler;
    private ApiTokenRepository $apiTokens;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $apiTokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $apiTokens);
        $this->apiTokens = $apiTokens;
        $this->handler = new RevokeApiTokenHandler($this->em, new NullLogger());
    }

    public function test_revoking_removes_the_token(): void
    {
        $owner = new User(username: 'revoke-handler', fullName: 'U', email: 'revoke-handler@example.com', password: 'x');
        $this->em->persist($owner);
        [$token] = ApiToken::issue($owner, 'handler-token', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $this->em->flush();
        $tokenId = $token->id;
        self::assertNotNull($tokenId);

        ($this->handler)(new RevokeApiTokenCommand($token));

        $this->em->clear();
        self::assertNull($this->apiTokens->find($tokenId));
    }
}
