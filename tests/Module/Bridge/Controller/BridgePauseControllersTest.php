<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A person pauses a bridge, or lets it start new work again, from the agents page. */
final class BridgePauseControllersTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_owner_pauses_a_bridge(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('pause');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'pause'));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/agents#agent-connection-'.$bridgeId);
        $stored = $this->storedBridge($owner, $bridgeId);
        self::assertTrue($stored->pauseRequested);
        self::assertSame((string) $owner->id, (string) $stored->pauseRequestedBy?->id);
    }

    public function test_the_owner_unpauses_a_bridge(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('unpause');
        $bridge->pauseRequested = true;
        $this->em()->flush();
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $bridgeId, 'unpause'));

        self::assertResponseRedirects('/projects/'.$project->id.'/agents#agent-connection-'.$bridgeId);
        self::assertFalse($this->storedBridge($owner, $bridgeId)->pauseRequested);
    }

    public function test_a_bridge_that_does_not_follow_the_project_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, , $bridge] = $this->scenario('elsewhere');
        $other = $this->project($this->em(), $owner, 'Bridge pause other');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($other, $bridgeId, 'pause'));

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->storedBridge($owner, $bridgeId)->pauseRequested);
    }

    public function test_a_bridge_of_another_owner_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, $project] = $this->scenario('mine');
        $someone = $this->user($this->em(), 'bridge-pause-someone@example.com');
        $theirs = $this->seedBridge($this->em(), $someone, projects: [(string) $project->id]);
        $theirsId = $theirs->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $theirsId, 'pause'));

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->storedBridge($someone, $theirsId)->pauseRequested);
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('csrf');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->url($project, $bridgeId, 'pause'), ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->storedBridge($owner, $bridgeId)->pauseRequested);
    }

    public function test_a_user_who_cannot_manage_the_project_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->scenario('theirs');
        $stranger = $this->user($this->em(), 'bridge-pause-stranger@example.com');
        $bridgeId = $bridge->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->url($project, $bridgeId, 'pause'));

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->storedBridge($owner, $bridgeId)->pauseRequested);
    }

    /** @return array{0: User, 1: Project, 2: Bridge} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'bridge-pause-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Bridge pause '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $em->flush();

        return [$owner, $project, $bridge];
    }

    private function url(Project $project, Uuid $bridgeId, string $action): string
    {
        return '/projects/'.$project->id.'/agents/'.$bridgeId.'/'.$action;
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's or the lookup's. */
    private function post(KernelBrowser $client, string $url): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function storedBridge(User $owner, Uuid $bridgeId): Bridge
    {
        $this->em()->clear();
        $bridge = $this->em()->find(Bridge::class, ['owner' => $owner->id, 'id' => $bridgeId]);
        self::assertInstanceOf(Bridge::class, $bridge);

        return $bridge;
    }
}
