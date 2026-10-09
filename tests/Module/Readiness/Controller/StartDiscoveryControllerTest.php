<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class StartDiscoveryControllerTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_the_owner_starts_discovery_and_returns_to_the_workshop(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start@example.com');
        $project = $this->boardProject($owner, 'Start discovery');
        $this->bridge($project);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->startUrl($project));

        self::assertResponseRedirects('/projects/'.$project->id);
        $runs = $this->runs($project);
        self::assertCount(1, $runs);
        self::assertSame(DiscoveryRunState::Requested, $runs[0]->state);
        self::assertSame(Actor::Human, $runs[0]->card->reporter);
        $client->followRedirect();
        self::assertSelectorExists('[data-readiness-row="repository"][data-readiness-discovery-state="requested"]');
    }

    public function test_a_start_with_no_live_bridge_shows_the_refusal(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-no-bridge@example.com');
        $project = $this->boardProject($owner, 'Start discovery no bridge');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->startUrl($project));

        self::assertResponseRedirects('/projects/'.$project->id);
        self::assertSame([], $this->runs($project));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Start a bridge that serves this project');
    }

    public function test_a_start_with_no_workflow_names_the_fix(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-no-workflow@example.com');
        $project = $this->boardProject($owner, 'Start discovery no workflow', bound: false);
        $this->bridge($project);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->startUrl($project));

        self::assertResponseRedirects('/projects/'.$project->id);
        self::assertSame([], $this->runs($project));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Choose a workflow for this project');
    }

    public function test_a_second_start_names_the_card_of_the_open_run(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-twice@example.com');
        $project = $this->boardProject($owner, 'Start discovery twice');
        $this->bridge($project);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->startUrl($project));
        $this->post($client, $this->startUrl($project));

        self::assertResponseRedirects('/projects/'.$project->id);
        $runs = $this->runs($project);
        self::assertCount(1, $runs);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Discovery already runs on card #'.$runs[0]->card->number);
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-csrf@example.com');
        $project = $this->boardProject($owner, 'Start discovery csrf');
        $this->bridge($project);
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->startUrl($project), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], $this->runs($project));
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-owner@example.com');
        $stranger = $this->user('discovery-start-stranger@example.com');
        $project = $this->boardProject($owner, 'Start discovery private');
        $this->bridge($project);
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->startUrl($project));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->runs($project));
    }

    public function test_the_route_takes_post_only(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-start-get@example.com');
        $project = $this->boardProject($owner, 'Start discovery get');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->startUrl($project));

        self::assertResponseStatusCodeSame(405);
    }

    private function boardProject(User $owner, string $name, bool $bound = true): Project
    {
        $project = $this->project($owner, $name);
        $seeder = static::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        $seeder->seed($project);
        if ($bound) {
            $shipped = static::getContainer()->get(ShippedTemplates::class);
            self::assertInstanceOf(ShippedTemplates::class, $shipped);
            $this->em()->persist(new WorkflowBinding($project, 'simple', 1, $shipped->source('simple')));
        }
        $this->em()->flush();

        return $project;
    }

    private function bridge(Project $project): void
    {
        $em = $this->em();
        $em->persist(new Bridge(AgentCredential::managed($em, $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable()));
        $em->flush();
    }

    /** @return list<DiscoveryRun> */
    private function runs(Project $project): array
    {
        $this->em()->clear();

        return array_values($this->discoveryRuns()->findBy(['project' => (string) $project->id]));
    }

    private function startUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/readiness/discovery/start';
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's. */
    private function post(KernelBrowser $client, string $url): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function discoveryRuns(): DiscoveryRunRepository
    {
        $repository = static::getContainer()->get(DiscoveryRunRepository::class);
        self::assertInstanceOf(DiscoveryRunRepository::class, $repository);

        return $repository;
    }
}
