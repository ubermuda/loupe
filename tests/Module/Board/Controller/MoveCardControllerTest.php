<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\MoveCardFormType;
use App\Tests\Module\Board\CardMovedOutbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class MoveCardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_move_inside_a_column_reorders_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-rank@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', 'backlog', 0);
        $second = $this->card($em, $project, 'Second', 'backlog', 1);
        $third = $this->card($em, $project, 'Third', 'backlog', 2);
        $ids = [$first->id, $second->id, $third->id];
        $em->clear();

        $client->loginUser($owner);
        // Empty lane fields, as a board with no lanes posts them: the rank still wins.
        $this->move($client, $third, 'backlog', 0, parent: '', before: '', after: '');

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

    public function test_a_move_to_another_column_lands_at_the_rank_it_asks_for(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-across@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Next first', 'next', 0);
        $this->card($em, $project, 'Next second', 'next', 1);
        $mover = $this->card($em, $project, 'Mover', 'backlog', 0);
        $moverId = $mover->id;
        $em->clear();

        $client->loginUser($owner);
        // The drop marker chose the top of the target column, so the card lands there.
        $this->move($client, $mover, 'next', 0);

        $em->clear();
        $moved = $em->find(Card::class, $moverId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertSame(0, $moved->position);
    }

    public function test_a_move_into_done_stamps_the_completion_and_answers_with_a_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-done@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Finishing', 'in-progress', 0);
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, 'done', null, stream: true);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '<turbo-stream action="replace" target="board">',
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();
        $moved = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('done', $moved->column->slug);
        self::assertNotNull($moved->completedAt);
    }

    public function test_a_drag_is_published_as_a_human_action(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-actor@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Dragged', 'backlog', 0);
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, 'next', null);

        self::assertResponseRedirects();
        $payload = CardMovedOutbox::onlyPayload(static::getContainer(), $project);
        self::assertSame(CardReporter::Human->value, $payload['actor'] ?? null);
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
        $this->move($client, $card, 'done', null);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_moving_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $this->disableBoard();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'move-flag-off@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Hidden');
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, 'next', null);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_column_of_another_board_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-cross-board@example.com');
        $project = $this->project($em, $owner);
        $other = $this->project($em, $owner, 'other-board');
        $card = $this->card($em, $project, 'Stays home');
        $cardId = $card->id;
        $foreign = (string) $this->column($other, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $card, 'next', null, columnId: $foreign);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        $unmoved = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $unmoved);
        self::assertSame('backlog', $unmoved->column->slug);
    }

    public function test_an_epic_with_open_children_is_refused_with_their_numbers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-epic-open@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic', 'in-progress'), CardType::Epic);
        $first = $this->card($em, $project, 'First child');
        $second = $this->card($em, $project, 'Second child', 'next');
        $first->parent = $epic;
        $second->parent = $epic;
        $em->flush();
        $epicId = $epic->id;
        $numbers = [$first->number, $second->number];
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $epic, 'done', null);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $client->followRedirect();
        self::assertSelectorTextContains('body', \sprintf('Move #%d, #%d to a done column first.', ...$numbers));
        $em->clear();
        $unmoved = $em->find(Card::class, $epicId);
        self::assertInstanceOf(Card::class, $unmoved);
        self::assertSame('in-progress', $unmoved->column->slug);
    }

    public function test_a_drop_in_another_lane_takes_that_epic_as_its_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-parent-set@example.com');
        $project = $this->project($em, $owner);
        $from = $this->typed($em, $this->card($em, $project, 'From epic', 'in-progress'), CardType::Epic);
        $to = $this->typed($em, $this->card($em, $project, 'To epic', 'in-progress', 1), CardType::Epic);
        $child = $this->childOf($em, $from, $this->card($em, $project, 'Child'));
        $childId = $child->id;
        $toId = $to->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $child, 'next', null, parent: (string) $toId);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        $moved = $em->find(Card::class, $childId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertEquals($toId, $moved->parent?->id);
    }

    public function test_a_drop_in_other_cards_clears_the_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-parent-clear@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'in-progress'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child'));
        $childId = $child->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $child, 'backlog', null, parent: 'none');

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        $moved = $em->find(Card::class, $childId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertNull($moved->parent);
    }

    public function test_a_drop_with_no_parent_field_keeps_the_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-parent-keep@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'in-progress'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child'));
        $childId = $child->id;
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $child, 'next', null, parent: '');

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        $moved = $em->find(Card::class, $childId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('next', $moved->column->slug);
        self::assertEquals($epicId, $moved->parent?->id);
    }

    public function test_an_epic_dropped_in_a_lane_is_refused_and_stays_put(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-parent-refused@example.com');
        $project = $this->project($em, $owner);
        $lane = $this->typed($em, $this->card($em, $project, 'Lane epic', 'in-progress'), CardType::Epic);
        $epic = $this->typed($em, $this->card($em, $project, 'Dropped epic'), CardType::Epic);
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $epic, 'next', null, stream: true, parent: (string) $lane->id);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'An epic cannot have a parent');
        $em->clear();
        $unmoved = $em->find(Card::class, $epicId);
        self::assertInstanceOf(Card::class, $unmoved);
        self::assertSame('backlog', $unmoved->column->slug);
        self::assertNull($unmoved->parent);
    }

    public function test_a_drop_next_to_a_neighbour_ranks_the_card_in_the_whole_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'move-neighbour@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', 'next', 0);
        $second = $this->card($em, $project, 'Second', 'next', 1);
        $mover = $this->card($em, $project, 'Mover');
        $ids = [$first->id, $second->id, $mover->id];
        $em->clear();

        $client->loginUser($owner);
        $this->move($client, $mover, 'next', null, before: (string) $second->id);
        $em->clear();
        self::assertSame([0, 2, 1], $this->positions($em, $ids));

        $this->move($client, $mover, 'next', null, after: (string) $second->id);
        $em->clear();
        self::assertSame([0, 1, 2], $this->positions($em, $ids));
    }

    /**
     * @param list<?\Symfony\Component\Uid\Uuid> $ids
     *
     * @return list<int>
     */
    private function positions(EntityManagerInterface $em, array $ids): array
    {
        return array_map(static function ($id) use ($em): int {
            $card = $em->find(Card::class, $id);
            self::assertInstanceOf(Card::class, $card);

            return $card->position;
        }, $ids);
    }

    private function move(
        KernelBrowser $client,
        Card $card,
        string $column,
        ?int $position,
        bool $stream = false,
        ?string $columnId = null,
        ?string $parent = null,
        ?string $before = null,
        ?string $after = null,
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
                'column' => $columnId ?? (string) $this->column($card->project, $column)->id,
                'position' => null === $position ? '' : (string) $position,
                '_token' => 'csrf-token',
                ...(null === $parent ? [] : ['parent' => $parent]),
                ...(null === $before ? [] : ['beforeCardId' => $before]),
                ...(null === $after ? [] : ['afterCardId' => $after]),
            ]],
            [],
            $server,
        );
    }
}
