<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\RevokeApiTokenCommand;
use App\Module\Account\Command\RevokeApiTokenHandler;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RevokeApiTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RevokeApiTokenHandler $handler;
    private ApiTokenRepository $apiTokens;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $apiTokens = self::getContainer()->get(ApiTokenRepository::class);
        self::assertInstanceOf(ApiTokenRepository::class, $apiTokens);
        $this->apiTokens = $apiTokens;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->projects = $projects;
        // From the container, not `new`: clearing the project's binding now
        // happens in a Project listener, so a hand-built handler would prove
        // nothing about whether the event reaches it.
        $handler = self::getContainer()->get(RevokeApiTokenHandler::class);
        self::assertInstanceOf(RevokeApiTokenHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_revoking_sets_revoked_at_but_keeps_the_row(): void
    {
        $owner = new User(fullName: 'U', email: 'revoke-handler@example.com', password: 'x');
        $this->em->persist($owner);
        [$token] = ApiToken::issue($owner, 'handler-token', ApiTokenScope::Mcp);
        $this->em->persist($token);
        $this->em->flush();
        $tokenId = $token->id;
        self::assertNotNull($tokenId);

        ($this->handler)(new RevokeApiTokenCommand($token));

        $this->em->clear();
        $fresh = $this->apiTokens->find($tokenId);
        self::assertInstanceOf(ApiToken::class, $fresh);
        self::assertNotNull($fresh->revokedAt);
    }

    public function test_revoking_a_project_bound_widget_token_clears_the_binding(): void
    {
        $owner = new User(fullName: 'U', email: 'revoke-widget@example.com', password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'revoke-widget-project');
        [$token] = ApiToken::issue($owner, 'Widget: revoke-widget-project', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $this->em->persist($project);
        $this->em->persist($token);
        $this->em->flush();
        $projectId = $project->id;
        self::assertNotNull($projectId);

        ($this->handler)(new RevokeApiTokenCommand($token));

        $this->em->clear();
        $freshProject = $this->projects->find($projectId);
        self::assertInstanceOf(Project::class, $freshProject);
        self::assertNull($freshProject->widgetToken, 'a revoked token must not remain bound to the project');
    }
}
