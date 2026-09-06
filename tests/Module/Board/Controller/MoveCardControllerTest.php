<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Form\MoveCardFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class MoveCardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_move_inside_a_priority_group_reorders_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-rank@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', CardStatus::Backlog, CardPriority::High, 0);
        $second = $this->card($em, $project, 'Second', CardStatus::Backlog, CardPriority::High, 1);
        $third = $this->card($em, $project, 'Third', CardStatus::Backlog, CardPriority::High, 2);
        $ids = [$first->id, $second->id, $third->id];
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $third, CardStatus::Backlog, CardPriority::High, 0);

        self::assertResponseRedirects();
        $em->clear();

        $positions = [];
        foreach ($ids as $id) {
            $found = $em->find(Card::class, $id);
            self::assertInstanceOf(Card::class, $found);
            $positions[] = $found->position;
        }
        // Third jumped to the head, and the two it passed shifted down by one.
        self::assertSame([1, 2, 0], $positions);
    }

    public function test_a_move_across_priority_groups_regrades_and_lands_at_the_end(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-regrade@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Low first', CardStatus::Backlog, CardPriority::Low, 0);
        $this->card($em, $project, 'Low second', CardStatus::Backlog, CardPriority::Low, 1);
        $climber = $this->card($em, $project, 'Climber', CardStatus::Backlog, CardPriority::High, 0);
        $climberId = $climber->id;
        $em->clear();

        $client->loginUser($owner);
        // A rank is offered and ignored: a re-grade always lands at the end.
        $this->move($client, $climber, CardStatus::Backlog, CardPriority::Low, 0);

        $em->clear();
        $moved = $em->find(Card::class, $climberId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame(CardPriority::Low, $moved->priority);
        self::assertSame(2, $moved->position);
    }

    public function test_a_move_into_done_stamps_the_completion_and_answers_with_a_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-done@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Finishing', CardStatus::InProgress, CardPriority::Medium, 0);
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, CardStatus::Done, CardPriority::Medium, null, stream: true);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '<turbo-stream action="replace" target="board">',
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();
        $moved = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame(CardStatus::Done, $moved->status);
        self::assertNotNull($moved->completedAt);
    }

    public function test_a_stranger_cannot_move_a_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-owner@example.com');
        $stranger = $this->user($em, 'move-stranger@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Not yours');
        $em->clear();

        $client->loginUser($stranger);
        $this->move($client, $card, CardStatus::Done, CardPriority::High, null);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_moving_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'move-flag-off@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Hidden');
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, CardStatus::Next, CardPriority::High, null);

        self::assertResponseStatusCodeSame(404);
    }

    private function move(
        KernelBrowser $client,
        Card $card,
        CardStatus $status,
        CardPriority $priority,
        ?int $position,
        bool $stream = false,
    ): void {
        $name = MoveCardFormType::nameFor($card);
        $url = '/projects/'.$card->project->id.'/board/cards/'.$card->id.'/move';

        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a
        // same-origin Referer lets stand in for the signed token. The Turbo
        // Accept header selects the stream branch over the redirect fallback.
        $server = ['HTTP_REFERER' => 'http://localhost'.$url];
        if ($stream) {
            $server['HTTP_ACCEPT'] = TurboBundle::STREAM_MEDIA_TYPE;
        }

        $client->request(
            Request::METHOD_POST,
            $url,
            [$name => [
                'status' => $status->value,
                'priority' => (string) $priority->value,
                'position' => null === $position ? '' : (string) $position,
                '_token' => 'csrf-token',
            ]],
            [],
            $server,
        );
    }
}
