<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\AuthorizeBoardRefreshCommand;
use App\Module\Board\Command\AuthorizeBoardRefreshHandler;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Support\FeatureFlags;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Uid\Uuid;

final class AuthorizeBoardRefreshHandlerTest extends TestCase
{
    private const string PROJECT_ID = '01a0984f-118f-7bb6-84b8-c40ec16d4d10';

    public function test_a_hub_on_the_same_site_authorizes_the_board(): void
    {
        [$authorize, $request] = $this->handler('https://hub.example.com/.well-known/mercure', new TestHandler());

        $url = $authorize(new AuthorizeBoardRefreshCommand($this->project()));

        self::assertSame('https://hub.example.com/.well-known/mercure?topic='.rawurlencode('https://loupe.example.com/projects/'.self::PROJECT_ID.'/board'), $url);
        self::assertArrayHasKey('', $request->attributes->all('_mercure_authorization_cookies'));
    }

    public function test_a_hub_on_another_site_leaves_the_board_without_live_refresh(): void
    {
        $log = new TestHandler();
        [$authorize, $request] = $this->handler('https://hub.elsewhere.test/.well-known/mercure', $log);

        self::assertNull($authorize(new AuthorizeBoardRefreshCommand($this->project())));
        self::assertSame([], $request->attributes->all('_mercure_authorization_cookies'));
        self::assertTrue($log->hasWarningThatContains('board.refresh_authorization_failed'));
    }

    /** @return array{AuthorizeBoardRefreshHandler, Request} */
    private function handler(string $hubUrl, TestHandler $log): array
    {
        $hub = new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            static fn (): string => 'id',
            new LcobucciFactory(str_repeat('s', 32)),
            $hubUrl,
        );
        $request = Request::create('https://loupe.example.com/projects/'.self::PROJECT_ID.'/board');
        $requests = new RequestStack();
        $requests->push($request);

        return [new AuthorizeBoardRefreshHandler(
            new ProjectTopicBuilder('https://loupe.example.com'),
            FeatureFlags::service([AgentPush::FLAG => true]),
            $requests,
            new Logger('test', [$log]),
            static fn (): Authorization => new Authorization(new HubRegistry($hub)),
            $hubUrl,
        ), $request];
    }

    private function project(): Project
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'refresh');
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::fromString(self::PROJECT_ID));

        return $project;
    }
}
