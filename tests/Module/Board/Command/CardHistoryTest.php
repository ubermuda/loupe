<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Service\CardEventCause;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CardHistoryTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->owner = $this->user('history-owner');
        $this->project = new Project($this->owner, 'history-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_card_a_person_creates_names_them_and_its_column(): void
    {
        $this->signIn($this->owner);

        $card = $this->create(CardReporter::Human);

        self::assertEquals([[
            'kind' => 'created',
            'actor_kind' => 'human',
            'actor_user_id' => (string) $this->owner->id,
            'detail' => ['column' => ['id' => (string) $this->column($this->project, 'backlog')->id, 'label' => 'board.card.status.backlog', 'slug' => 'backlog'], 'type' => 'feature'],
        ]], $this->history($card));
    }

    public function test_a_card_a_reviewer_raises_names_no_account_even_in_a_signed_in_session(): void
    {
        $this->signIn($this->owner);

        $card = $this->create(CardReporter::Reviewer);

        $rows = $this->history($card);
        self::assertCount(1, $rows);
        self::assertSame('created', $rows[0]['kind']);
        self::assertSame('reviewer', $rows[0]['actor_kind']);
        self::assertNull($rows[0]['actor_user_id']);
    }

    public function test_a_move_to_another_column_records_both_ends_and_the_person(): void
    {
        $this->signIn($this->owner);
        $card = $this->create(CardReporter::Human);

        $this->move($card, CardReporter::Human, 'next');

        self::assertEquals([
            'kind' => 'moved',
            'actor_kind' => 'human',
            'actor_user_id' => (string) $this->owner->id,
            'detail' => [
                'from' => ['id' => (string) $this->column($this->project, 'backlog')->id, 'label' => 'board.card.status.backlog', 'slug' => 'backlog'],
                'to' => ['id' => (string) $this->column($this->project, 'next')->id, 'label' => 'board.card.status.next', 'slug' => 'next'],
                'cause' => null,
            ],
        ], $this->history($card)[1]);
    }

    public function test_a_new_rank_in_the_same_column_records_nothing(): void
    {
        $card = $this->create(CardReporter::Agent);
        $this->create(CardReporter::Agent);

        $this->move($card, CardReporter::Human, 'backlog', position: 1);

        // Guard: the rank did change, so the absence below is not a move that never ran.
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT position FROM board_cards WHERE id = :id', ['id' => (string) $card->id]));
        self::assertSame(['created'], array_column($this->history($card), 'kind'));
    }

    public function test_a_move_by_the_app_names_no_account_and_records_its_cause(): void
    {
        $this->signIn($this->owner);
        $card = $this->create(CardReporter::Agent);

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $update(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $this->column($this->project, 'done'), cause: CardEventCause::merged(42)));

        $row = $this->history($card)[1];
        self::assertSame('system', $row['actor_kind']);
        self::assertNull($row['actor_user_id']);
        self::assertEquals(['type' => 'merged', 'pullRequest' => 42], $row['detail']['cause']);
        self::assertSame('done', $row['detail']['to']['slug']);
    }

    public function test_a_column_delete_records_a_move_for_each_card_it_carried(): void
    {
        $this->signIn($this->owner);
        $first = $this->create(CardReporter::Agent, 'next');
        $second = $this->create(CardReporter::Agent, 'next');
        $deletedId = (string) $this->column($this->project, 'next')->id;

        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);
        $delete(new DeleteBoardColumnCommand($this->column($this->project, 'next'), CardReporter::Human, $this->column($this->project, 'in-progress')));

        foreach ([$first, $second] as $card) {
            self::assertEquals([
                'kind' => 'moved',
                'actor_kind' => 'human',
                'actor_user_id' => (string) $this->owner->id,
                'detail' => [
                    'from' => ['id' => $deletedId, 'label' => 'board.card.status.next', 'slug' => 'next'],
                    'to' => ['id' => (string) $this->column($this->project, 'in-progress')->id, 'label' => 'board.card.status.in-progress', 'slug' => 'in-progress'],
                    'cause' => ['type' => 'column-deleted', 'column' => 'board.card.status.next'],
                ],
            ], $this->history($card)[1]);
        }
    }

    public function test_a_deleted_account_leaves_its_rows_with_no_one(): void
    {
        $collaborator = $this->user('history-collaborator');
        $this->em->flush();
        $this->signIn($collaborator);
        $card = $this->create(CardReporter::Human);
        self::assertSame((string) $collaborator->id, $this->history($card)[0]['actor_user_id']);

        $purger = self::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $purger);
        $purger->purge($collaborator);
        $this->em->clear();

        $rows = $this->history($card);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['actor_user_id']);
    }

    public function test_a_deleted_card_takes_its_rows_with_it(): void
    {
        $card = $this->create(CardReporter::Agent);
        $cardId = (string) $card->id;
        self::assertSame(1, $this->rowCount('card_id', $cardId));

        $this->em->remove($card);
        $this->em->flush();

        self::assertSame(0, $this->rowCount('card_id', $cardId));
    }

    public function test_a_deleted_project_takes_its_rows_with_it(): void
    {
        $this->create(CardReporter::Agent);
        $projectId = (string) $this->project->id;
        self::assertSame(1, $this->rowCount('project_id', $projectId));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($this->project);
        $this->em->clear();

        self::assertSame(0, $this->rowCount('project_id', $projectId));
    }

    private function create(CardReporter $reporter, ?string $slug = null): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: 'History',
            body: '',
            type: 'feature',
            column: null === $slug ? null : $this->column($this->project, $slug),
            reporter: $reporter,
        ));
    }

    private function move(Card $card, CardReporter $actor, string $slug, ?int $position = null): void
    {
        $move = self::getContainer()->get(MoveCardHandler::class);
        self::assertInstanceOf(MoveCardHandler::class, $move);
        $move(new MoveCardCommand($card, $actor, $this->column($this->project, $slug), $position));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function user(string $prefix): User
    {
        $user = new User(fullName: 'Riley', email: $prefix.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    /** @return list<array{kind: mixed, actor_kind: mixed, actor_user_id: mixed, detail: mixed}> oldest first */
    private function history(Card $card): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT kind, actor_kind, actor_user_id, detail FROM board_card_events WHERE card_id = :card ORDER BY occurred_at, id',
            ['card' => (string) $card->id],
        );

        return array_map(static fn (array $row): array => [
            'kind' => $row['kind'],
            'actor_kind' => $row['actor_kind'],
            'actor_user_id' => $row['actor_user_id'],
            'detail' => json_decode((string) $row['detail'], true, flags: \JSON_THROW_ON_ERROR),
        ], $rows);
    }

    private function rowCount(string $column, string $id): int
    {
        return (int) $this->em->getConnection()->fetchOne(\sprintf('SELECT COUNT(*) FROM board_card_events WHERE %s = :id', $column), ['id' => $id]);
    }
}
