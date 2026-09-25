<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\EventListener\DeleteBoardDataOnProjectDeleting;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeleteBoardDataOnProjectDeletingTest extends KernelTestCase
{
    public function test_deleting_a_project_removes_its_cards_and_spares_a_sibling(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);

        $owner = new User(fullName: 'Riley', email: 'board-delete-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);

        $doomed = $this->seedBoard($em, $owner, 'doomed');
        $spared = $this->seedBoard($em, $owner, 'spared');
        $em->flush();

        $doomedId = (string) $doomed->id;
        $sparedId = (string) $spared->id;

        $conn = $em->getConnection();
        // Guard: without it the absence assertions below also pass on a fixture
        // that never wrote a card.
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests', []));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_links', []));
        self::assertSame(4, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = :id', ['id' => $doomedId]));

        $deleter->delete($doomed);
        $em->clear();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests', []));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_links', []));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $sparedId]));
        self::assertSame(4, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_columns WHERE project_id = :id', ['id' => $sparedId]));
    }

    /**
     * The parent key has no ON DELETE action, so Postgres checks it at the end
     * of the one DELETE that removes the epic and its child together.
     */
    public function test_deleting_a_project_removes_an_epic_and_its_child_in_one_statement(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);

        $owner = new User(fullName: 'Riley', email: 'board-delete-epic-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'epic-'.uniqid());
        $em->persist($project);
        $seeder = self::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        [$backlog] = $seeder->seed($project);
        $epic = new Card(project: $project, column: $backlog, title: 'The epic', body: '', number: 1, type: CardType::Epic);
        $child = new Card(project: $project, column: $backlog, title: 'The child', body: '', number: 2);
        $child->parent = $epic;
        $em->persist($epic);
        $em->persist($child);
        $em->flush();
        $projectId = (string) $project->id;

        $conn = $em->getConnection();
        // Guard: the child row points at the epic, so the delete below meets the key.
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id AND parent_card_id IS NOT NULL', ['id' => $projectId]));

        $deleter->delete($project);
        $em->clear();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $projectId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $projectId]));
    }

    /**
     * The listener runs alone here. Through ProjectDeleter the foreign key's
     * cascade would remove the reports too, and hide a listener that did not.
     */
    public function test_the_listener_deletes_the_bridge_rule_reports_of_its_project_only(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $listener = self::getContainer()->get(DeleteBoardDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteBoardDataOnProjectDeleting::class, $listener);

        $owner = new User(fullName: 'Riley', email: 'board-delete-reports-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $doomed = $this->seedBoard($em, $owner, 'doomed');
        $spared = $this->seedBoard($em, $owner, 'spared');
        $rules = [['name' => 'plan', 'on' => 'board.card_moved', 'columns' => ['next'], 'state' => 'live', 'reason' => null]];
        $em->persist(new BridgeRuleReport($doomed, Uuid::v4(), $rules));
        $em->persist(new BridgeRuleReport($doomed, Uuid::v4(), $rules));
        $em->persist(new BridgeRuleReport($spared, Uuid::v4(), $rules));
        $em->flush();

        $conn = $em->getConnection();
        $count = static fn (Project $project): int => (int) $conn->fetchOne('SELECT COUNT(*) FROM board_bridge_rule_reports WHERE project_id = :id', ['id' => (string) $project->id]);
        self::assertSame(2, $count($doomed));

        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $count($doomed));
        self::assertSame(1, $count($spared));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
    }

    private function seedBoard(EntityManagerInterface $em, User $owner, string $name): Project
    {
        $project = new Project($owner, $name.'-'.uniqid());
        $em->persist($project);
        $seeder = self::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        [$backlog] = $seeder->seed($project);

        $card = new Card(project: $project, column: $backlog, title: 'Ship it', body: 'Body', number: 1);
        if ('doomed' === $name) {
            $card->pullRequests->add(new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/1', Forge::GitHub, 'ubermuda/loupe', 1));
            $blocked = new Card(project: $project, column: $backlog, title: 'Then this', body: 'Body', number: 2);
            $em->persist($blocked);
            $em->persist(new CardLink($card, $blocked, CardLinkKind::Blocks));
        }
        $em->persist($card);

        return $project;
    }
}
