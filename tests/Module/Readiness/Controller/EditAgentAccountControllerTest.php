<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Module\Bridge\Entity\Bridge;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Readiness\ReadinessScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class EditAgentAccountControllerTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_the_owner_sees_the_steps_and_records_a_login(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-save@example.com');
        $project = $this->project($owner, 'Agent account save');
        $url = $this->url($project);
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-settings-nav__item--active[href="/projects/'.$project->id.'/settings/readiness"]');
        self::assertSelectorTextContains('[data-agent-account]', 'loupe agent-account set');
        self::assertSelectorTextContains('[data-agent-account-state="open"]', 'No login recorded yet');

        $client->submitForm('Save', ['update_agent_account_form[login]' => 'Acme-Agent']);
        self::assertResponseRedirects($url);
        self::assertSame('Acme-Agent', $this->reload($project)->agentGitHubLogin);

        $client->followRedirect();
        self::assertSelectorTextContains('[data-agent-account-state="open"]', 'No running bridge pushes as Acme-Agent yet');
    }

    public function test_the_page_says_when_a_running_bridge_pushes_as_the_login(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-done@example.com');
        $project = $this->project($owner, 'Agent account done');
        $project->agentGitHubLogin = 'Acme-Agent';
        $bridge = new Bridge($owner, Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->pushLogin = 'acme-agent';
        $this->em()->persist($bridge);
        $this->em()->flush();
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $this->url($project));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-agent-account-state="done"]', 'Agents push as Acme-Agent');
    }

    public function test_an_empty_login_clears_the_recorded_one(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-clear@example.com');
        $project = $this->project($owner, 'Agent account clear');
        $project->agentGitHubLogin = 'acme-agent';
        $this->em()->flush();
        $this->em()->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, $this->url($project));
        self::assertSame('acme-agent', $crawler->filter('#update_agent_account_form_login')->attr('value'));
        $client->submitForm('Save', ['update_agent_account_form[login]' => '']);

        self::assertResponseRedirects($this->url($project));
        self::assertNull($this->reload($project)->agentGitHubLogin);
    }

    public function test_a_login_of_a_bad_shape_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-shape@example.com');
        $project = $this->project($owner, 'Agent account shape');
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $this->url($project));
        $client->submitForm('Save', ['update_agent_account_form[login]' => 'acme--agent']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.lp-field-errors', 'single hyphens');
        self::assertNull($this->reload($project)->agentGitHubLogin);
    }

    public function test_the_login_that_installed_the_github_app_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-installer@example.com');
        $project = $this->project($owner, 'Agent account installer');
        $this->em()->persist(new GitHubInstallation($project, 9_200_001, 'Acme', GitHubRepositorySelection::Selected));
        $this->em()->flush();
        $this->em()->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, $this->url($project));
        $client->submitForm('Save', ['update_agent_account_form[login]' => 'acme']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.lp-field-errors', 'an account other than the one that installed the GitHub App');
        self::assertNull($this->reload($project)->agentGitHubLogin);
    }

    public function test_the_readiness_settings_page_links_the_page(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-link@example.com');
        $project = $this->project($owner, 'Agent account link');
        $this->em()->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/settings/readiness');

        self::assertResponseIsSuccessful();
        self::assertSame($this->url($project), $crawler->filter('[data-agent-account-link]')->attr('href'));
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('agent-account-owner@example.com');
        $stranger = $this->user('agent-account-stranger@example.com');
        $project = $this->project($owner, 'Agent account private');
        $this->em()->clear();
        $client->loginUser($stranger);

        $client->request(Request::METHOD_GET, $this->url($project));
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, $this->url($project), ['update_agent_account_form' => ['login' => 'acme-agent']]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($project)->agentGitHubLogin);
    }

    private function url(Project $project): string
    {
        return '/projects/'.$project->id.'/readiness/agent-account';
    }
}
