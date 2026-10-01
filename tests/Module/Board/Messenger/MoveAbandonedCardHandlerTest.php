<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Messenger\MoveAbandonedCard;
use App\Module\Board\Messenger\MoveAbandonedCardHandler;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class MoveAbandonedCardHandlerTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->enableBoard();
        $this->project = $this->makeProject('abandoned-card');
    }

    public function test_a_card_whose_pull_requests_all_closed_moves_to_the_backlog(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->link($card, 6, PullRequestState::Closed);

        $this->handle($card);

        self::assertSame('backlog', $this->storedColumnOf($card));
        self::assertEquals([['system', null, 'in-progress', 'backlog', ['type' => 'abandoned']]], $this->history($card));
    }

    public function test_an_older_queued_move_does_nothing(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $older = $this->queueToken($card);
        $newer = $this->queueToken($card);

        $this->handleWith($card, $older);
        self::assertSame('in-progress', $this->storedColumnOf($card));

        $this->handleWith($card, $newer);
        self::assertSame('backlog', $this->storedColumnOf($card));
    }

    public function test_a_redelivered_move_leaves_a_card_a_person_took_out_of_the_backlog(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $token = $this->queueToken($card);
        $this->handleWith($card, $token);
        self::assertSame('backlog', $this->storedColumnOf($card));
        $this->moveBehindTheEntity($card, 'in-progress');

        $this->handleWith($card, $token);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_card_whose_column_turned_terminal_since_it_loaded_stays(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->em->getConnection()->executeStatement(
            'UPDATE board_columns SET terminal = true WHERE id = :column',
            ['column' => (string) $card->column->id],
        );

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_card_with_no_queued_move_stays(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);

        $this->handleWith($card, Uuid::v7());

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_pull_request_linked_since_the_close_keeps_the_card(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO board_card_pull_requests (id, card_id, url, forge, repository, number, added_at)
             VALUES (:id, :card, 'https://github.com/Acme/Widgets/pull/7', 'github', 'Acme/Widgets', 7, NOW())",
            ['id' => Uuid::v7()->toRfc4122(), 'card' => (string) $card->id],
        );
        $row = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 7);
        $this->em->persist($row);
        $this->em->flush();

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
        self::assertSame([], $this->history($card));
        self::assertNull($this->storedToken($card));
    }

    public function test_a_pull_request_reopened_since_the_close_keeps_the_card(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->em->getConnection()->executeStatement(
            "UPDATE forge_pull_requests SET state = 'open' WHERE project_id = :project",
            ['project' => (string) $this->project->id],
        );

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_merged_link_keeps_the_card(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->link($card, 6, PullRequestState::Merged);

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_link_on_another_forge_keeps_the_card(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $link = new CardPullRequest($card, 'https://git.example.com/acme/widgets/merge/6');
        $card->pullRequests->add($link);
        $this->em->persist($link);
        $this->em->flush();

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_a_card_a_person_moved_to_a_terminal_column_stays(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->moveBehindTheEntity($card, 'done');

        $this->handle($card);

        self::assertSame('done', $this->storedColumnOf($card));
        self::assertSame([], $this->history($card));
        self::assertNull($this->storedToken($card));
    }

    public function test_disabled_automation_moves_nothing(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($this->project)->enabled = false;
        $this->em->flush();

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
        self::assertNull($this->storedToken($card));
    }

    public function test_the_board_off_moves_nothing(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->disableBoard();

        $this->handle($card);

        self::assertSame('in-progress', $this->storedColumnOf($card));
    }

    public function test_an_epic_with_an_open_child_stays(): void
    {
        $epic = $this->linkedCard('in-progress', PullRequestState::Closed);
        $epic->type = CardType::Epic;
        $child = new Card($this->project, $this->column($this->project, 'next'), 'A child', '', ++$this->cardNumber);
        $child->parent = $epic;
        $this->em->persist($child);
        $this->em->flush();

        $this->handle($epic);

        self::assertSame('in-progress', $this->storedColumnOf($epic));
        self::assertNull($this->storedToken($epic));
    }

    public function test_an_older_queued_move_leaves_the_newer_token(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $this->moveBehindTheEntity($card, 'done');
        $older = $this->queueToken($card);
        $newer = $this->queueToken($card);

        $this->handleWith($card, $older);

        self::assertSame($newer->toRfc4122(), $this->storedToken($card));
    }

    public function test_a_deleted_card_does_nothing(): void
    {
        $card = $this->linkedCard('in-progress', PullRequestState::Closed);
        $cardId = $card->id ?? throw new \LogicException('A persisted card has an id.');
        $this->em->remove($card);
        $this->em->flush();
        $handler = self::getContainer()->get(MoveAbandonedCardHandler::class);
        self::assertInstanceOf(MoveAbandonedCardHandler::class, $handler);

        $handler(new MoveAbandonedCard($cardId, Uuid::v7()));

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE project_id = :project AND type = 'board.card_moved'",
            ['project' => (string) $this->project->id],
        ));
    }

    private function storedToken(Card $card): ?string
    {
        $token = $this->em->getConnection()->fetchOne(
            'SELECT abandoned_move_token FROM board_card_automations WHERE card_id = :card',
            ['card' => (string) $card->id],
        );

        return \is_string($token) ? $token : null;
    }

    private function handle(Card $card): void
    {
        $this->handleWith($card, $this->queueToken($card));
    }

    private function handleWith(Card $card, Uuid $token): void
    {
        $handler = self::getContainer()->get(MoveAbandonedCardHandler::class);
        self::assertInstanceOf(MoveAbandonedCardHandler::class, $handler);
        $handler(new MoveAbandonedCard($card->id ?? throw new \LogicException('A persisted card has an id.'), $token));
    }

    /** Records a token as the newest queued move of the card, as a close does. */
    private function queueToken(Card $card): Uuid
    {
        $token = Uuid::v7();
        $automations = self::getContainer()->get(CardAutomationRepository::class);
        self::assertInstanceOf(CardAutomationRepository::class, $automations);
        $this->em->wrapInTransaction(function () use ($automations, $card, $token): void {
            $automations->findOrCreateForUpdate($card)->abandonedMoveToken = $token;
            $this->em->flush();
        });

        return $token;
    }

    private function linkedCard(string $slug, PullRequestState $state): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->link($card, 5, $state);

        return $card;
    }

    private function link(Card $card, int $number, PullRequestState $state): void
    {
        $link = new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/'.$number, Forge::GitHub, 'Acme/Widgets', $number);
        $card->pullRequests->add($link);
        $this->em->persist($link);
        $row = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', $number);
        $row->state = $state;
        $this->em->persist($row);
        $this->em->flush();
    }

    /** Another request commits a move that this entity manager has not seen. */
    private function moveBehindTheEntity(Card $card, string $slug): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE board_cards SET column_id = :column WHERE id = :card',
            ['column' => (string) $this->column($this->project, $slug)->id, 'card' => (string) $card->id],
        );
    }

    private function storedColumnOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT c.slug FROM board_cards b JOIN board_columns c ON c.id = b.column_id WHERE b.id = :card',
            ['card' => (string) $card->id],
        );
    }

    /** @return list<array{mixed, mixed, mixed, mixed, mixed}> actor kind, actor user, from slug, to slug, cause */
    private function history(Card $card): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, detail FROM board_card_events WHERE card_id = :card AND kind = 'moved' ORDER BY occurred_at, id",
            ['card' => (string) $card->id],
        );

        return array_map(static function (array $row): array {
            $detail = json_decode((string) $row['detail'], true, flags: \JSON_THROW_ON_ERROR);

            return [$row['actor_kind'], $row['actor_user_id'], $detail['from']['slug'], $detail['to']['slug'], $detail['cause']];
        }, $rows);
    }
}
