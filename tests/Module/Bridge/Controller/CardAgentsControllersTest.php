<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Uid\Uuid;

/** A person pauses the agents on a card, and lets them run again. */
final class CardAgentsControllersTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_owner_pauses_the_agents_on_a_card(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('pause');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'pause'));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertTrue($this->isHeld($project, $cardId));
        self::assertSame(1, $this->countEvents('board.card_held', $cardId));
    }

    public function test_a_pause_from_the_card_frame_returns_to_the_frame(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('pause-frame');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'pause'), ['HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs/card/'.$cardId);
        self::assertTrue($this->isHeld($project, $cardId));
    }

    public function test_a_pause_of_a_paused_card_from_the_frame_renders_the_reason(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('pause-twice');
        $this->hold($project, $cardId);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'pause'), ['HTTP_TURBO_FRAME' => 'card-worker-runs']);
        $crawler = $client->getCrawler();

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Agents are already paused on this card.',
            $crawler->filter('turbo-frame#card-worker-runs [data-worker-run-command-flash]')->text(),
        );
        self::assertSame([], $this->flashes($client, 'error'));
        self::assertSame(0, $this->countEvents('board.card_held', $cardId));
    }

    public function test_the_owner_lets_the_agents_on_a_card_run(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('release');
        $this->hold($project, $cardId);
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'release'));

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertFalse($this->isHeld($project, $cardId));
        self::assertSame(1, $this->countEvents('board.card_released', $cardId));
    }

    public function test_a_release_of_a_card_that_is_not_paused_flashes_the_reason(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('release-free');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'release'));

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertSame(['Agents are not paused on this card.'], $this->flashes($client, 'error'));
        self::assertSame(0, $this->countEvents('board.card_released', $cardId));
    }

    public function test_a_user_who_cannot_manage_the_project_is_refused(): void
    {
        $client = static::createClient();
        [, $project, $cardId] = $this->scenario('theirs');
        $stranger = $this->user($this->em(), 'card-agents-stranger@example.com');
        $this->hold($project, $cardId);
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->url($project, $cardId, 'pause'));
        self::assertResponseStatusCodeSame(403);

        $this->post($client, $this->url($project, $cardId, 'release'));
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->isHeld($project, $cardId));
    }

    public function test_an_unknown_card_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, $project] = $this->scenario('unknown');
        $unknown = Uuid::v7();
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $unknown, 'pause'));
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->isHeld($project, $unknown));

        $this->post($client, $this->url($project, $unknown, 'release'));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_pause_of_a_card_deleted_after_the_first_lookup_is_not_found(): void
    {
        $client = static::createClient();
        static::getContainer()->set(CardColumnLookupInterface::class, new class implements CardColumnLookupInterface {
            private int $calls = 0;

            public function columnOf(Project $project, Uuid $cardId): ?string
            {
                return 0 === $this->calls++ ? 'implementation' : null;
            }

            public function cardIdOfNumber(Project $project, int $number): ?Uuid
            {
                return null;
            }
        });
        [$owner, $project, $cardId] = $this->scenario('gone');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $cardId, 'pause'));

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->isHeld($project, $cardId));
    }

    public function test_a_card_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, $project] = $this->scenario('cross');
        [, , $otherCardId] = $this->scenario('cross-other');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $this->url($project, $otherCardId, 'pause'));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $cardId] = $this->scenario('csrf');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->url($project, $cardId, 'pause'), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, $this->url($project, $cardId, 'release'), ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->isHeld($project, $cardId));
    }

    /** @return array{User, Project, Uuid} */
    private function scenario(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'card-agents-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Agents '.substr(md5($name), 0, 8));
        $card = new Card($project, new BoardColumn($project, 'Implementation', 'implementation', 0), 'Paused card', '', 1);
        $em->persist($card->column);
        $em->persist($card);
        $em->flush();
        $cardId = $card->id;
        self::assertInstanceOf(Uuid::class, $cardId);

        return [$owner, $project, $cardId];
    }

    private function url(Project $project, Uuid $cardId, string $action): string
    {
        return '/projects/'.$project->id.'/worker-runs/card/'.$cardId.'/'.$action;
    }

    /** @param array<string, string> $server */
    private function post(KernelBrowser $client, string $url, array $server = []): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url] + $server);
    }

    private function cardHolds(): CardHolds
    {
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    private function hold(Project $project, Uuid $cardId): void
    {
        $this->cardHolds()->hold($project, $cardId, null);
        $this->em()->flush();
    }

    private function isHeld(Project $project, Uuid $cardId): bool
    {
        $this->em()->clear();
        $fresh = $this->em()->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $fresh);

        return $this->cardHolds()->isHeld($fresh, $cardId);
    }

    private function countEvents(string $type, Uuid $cardId): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE type = ? AND payload::jsonb->'subject'->>'id' = ?",
            [$type, (string) $cardId],
        );
    }

    /** @return array<mixed> */
    private function flashes(KernelBrowser $client, string $type): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peek($type);
    }
}
