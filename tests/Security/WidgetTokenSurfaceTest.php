<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Splitting ROLE_API_SITE_REVIEW in two must not touch the widget. A widget
 * token is embedded in page HTML that is already deployed, so an owner cannot
 * roll it without editing every page that carries it.
 *
 * The assertion is deliberately "not 401 and not 403" rather than a success
 * code. Four of these paths are writes that need a real body, so a 400, 404 or
 * 422 from the controller is the proof: authentication and authorization both
 * passed, and the request failed on its payload.
 */
final class WidgetTokenSurfaceTest extends WebTestCase
{
    /**
     * Every path the embedded widget calls, with the method it calls it by.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function widgetPaths(): iterable
    {
        $id = (string) Uuid::v7();

        yield 'add comment' => [Request::METHOD_POST, '/api/site-review/comments'];
        yield 'update comment' => [Request::METHOD_PATCH, '/api/site-review/comments/'.$id];
        yield 'delete comment' => [Request::METHOD_DELETE, '/api/site-review/comments/'.$id];
        yield 'resolve comment' => [Request::METHOD_POST, '/api/site-review/comments/'.$id.'/resolve'];
        yield 'pending comments' => [Request::METHOD_GET, '/api/site-review/review'];
        yield 'list cards' => [Request::METHOD_GET, '/api/board/cards'];
        yield 'create card' => [Request::METHOD_POST, '/api/board/cards'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function agentPaths(): iterable
    {
        yield 'projects' => [Request::METHOD_GET, '/api/projects'];
        yield 'events' => [Request::METHOD_GET, '/api/events'];
        yield 'columns' => [Request::METHOD_GET, '/api/projects/anything/board/columns'];
    }

    #[DataProvider('widgetPaths')]
    public function test_a_widget_token_still_reaches_every_widget_path(string $method, string $path): void
    {
        $client = static::createClient();
        $raw = $this->issueWidgetToken('widget-surface@example.com', 'widget-surface-site');

        $this->call($client, $method, $path, $raw);

        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $status, $method.' '.$path.' rejected the widget token');
        self::assertNotSame(Response::HTTP_FORBIDDEN, $status, $method.' '.$path.' rejected the widget token');
    }

    #[DataProvider('widgetPaths')]
    public function test_an_agent_token_reaches_no_widget_path(string $method, string $path): void
    {
        $client = static::createClient();
        $raw = $this->issueAccountToken(ApiTokenScope::Agent, 'agent-on-widget@example.com');

        $this->call($client, $method, $path, $raw);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString(
            '{"error":"insufficient_scope"}',
            (string) $client->getResponse()->getContent(),
            $method.' '.$path.' must refuse on scope, not on project binding',
        );
    }

    #[DataProvider('agentPaths')]
    public function test_a_widget_token_reaches_no_agent_path(string $method, string $path): void
    {
        $client = static::createClient();
        $raw = $this->issueWidgetToken('widget-on-agent@example.com', 'widget-on-agent-site');

        $this->call($client, $method, $path, $raw);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString(
            '{"error":"insufficient_scope"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    #[DataProvider('agentPaths')]
    public function test_an_agent_token_reaches_every_agent_path(string $method, string $path): void
    {
        $client = static::createClient();
        $raw = $this->issueAccountToken(ApiTokenScope::Agent, 'agent-on-agent@example.com');

        $this->call($client, $method, $path, $raw);

        $status = $client->getResponse()->getStatusCode();
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $status, $method.' '.$path.' rejected the agent token');
        self::assertNotSame(Response::HTTP_FORBIDDEN, $status, $method.' '.$path.' rejected the agent token');
    }

    private function call(KernelBrowser $client, string $method, string $path, string $raw): void
    {
        $client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');
    }

    /** @param non-empty-string $email */
    private function issueWidgetToken(string $email, string $siteName): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project = new Project($user, $siteName);
        $project->widgetToken = $token;
        $em->persist($project);
        $em->flush();

        return $raw;
    }

    /** @param non-empty-string $email */
    private function issueAccountToken(ApiTokenScope $scope, string $email): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'account-tok', $scope);
        $em->persist($token);
        $em->persist(new Project($user, 'account-'.substr(md5($email), 0, 8)));
        $em->flush();

        return $raw;
    }
}
