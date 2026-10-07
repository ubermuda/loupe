<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Tests\Module\Readiness\ReadinessScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HideReadinessGuideControllerTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_the_owner_hides_the_guide_and_returns_to_the_workshop(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-hide@example.com');
        $project = $this->project($owner, 'Hide guide');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->hideUrl((string) $project->id));

        self::assertResponseRedirects('/projects/'.$project->id);
        self::assertNotNull($this->reload($project)->readinessGuideHiddenAt);
        $client->followRedirect();
        self::assertSelectorNotExists('[data-readiness-guide]');
    }

    public function test_a_second_hide_keeps_the_first_date(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-hide-again@example.com');
        $project = $this->project($owner, 'Hide guide again');
        $hiddenAt = new \DateTimeImmutable('2026-01-02 03:04:05');
        $project->readinessGuideHiddenAt = $hiddenAt;
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->hideUrl((string) $project->id));

        self::assertResponseRedirects('/projects/'.$project->id);
        self::assertEquals($hiddenAt, $this->reload($project)->readinessGuideHiddenAt);
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-hide-csrf@example.com');
        $project = $this->project($owner, 'Hide guide csrf');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->hideUrl((string) $project->id), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->request(Request::METHOD_POST, $this->hideUrl((string) $project->id));
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->reload($project)->readinessGuideHiddenAt);
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-hide-owner@example.com');
        $stranger = $this->user('readiness-hide-stranger@example.com');
        $project = $this->project($owner, 'Hide guide private');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->hideUrl((string) $project->id));

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($project)->readinessGuideHiddenAt);
    }

    public function test_the_route_takes_post_only(): void
    {
        $client = static::createClient();
        $owner = $this->user('readiness-hide-get@example.com');
        $project = $this->project($owner, 'Hide guide get');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->hideUrl((string) $project->id));

        self::assertResponseStatusCodeSame(405);
    }

    private function hideUrl(string $projectId): string
    {
        return '/projects/'.$projectId.'/readiness/guide/hide';
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's. */
    private function post(KernelBrowser $client, string $url): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }
}
