<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Deletion;

use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Board registers no AccountDataPurgerInterface, because a card is reachable
 * from a user only through its project. This proves the chain that replaces
 * one: ProjectAccountPurger deletes every project the user owns, and
 * DeleteBoardDataOnProjectDeleting clears each project's cards.
 */
final class BoardAccountDeletionTest extends KernelTestCase
{
    public function test_deleting_an_account_removes_its_cards_and_spares_another_owner(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $purger = self::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $purger);

        $owner = $this->user($em, 'board-purge-owner');
        $stranger = $this->user($em, 'board-purge-stranger');

        $doomed = $this->seedBoard($em, $owner);
        $spared = $this->seedBoard($em, $stranger);
        $em->flush();

        $doomedId = (string) $doomed->id;
        $sparedId = (string) $spared->id;

        $conn = $em->getConnection();
        // Guard: without it every absence assertion below also passes on a
        // fixture that wrote no card in the first place.
        self::assertSame(1, $this->cardCount($conn, $doomedId));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests l JOIN board_cards c ON l.card_id = c.id WHERE c.project_id = :id', ['id' => $doomedId]));

        $purger->purge($owner);
        $em->clear();

        self::assertSame(0, $this->cardCount($conn, $doomedId));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests l JOIN board_cards c ON l.card_id = c.id WHERE c.project_id = :id', ['id' => $doomedId]));
        self::assertSame(1, $this->cardCount($conn, $sparedId));
    }

    private function cardCount(Connection $conn, string $projectId): int
    {
        return (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $projectId]);
    }

    /** @param non-empty-string $label */
    private function user(EntityManagerInterface $em, string $label): User
    {
        $user = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($user);

        return $user;
    }

    private function seedBoard(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'board-purge-'.uniqid());
        $em->persist($project);

        $card = new Card(project: $project, title: 'Ship it', body: 'Body', number: 1);
        $card->pullRequests->add(new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/1', Forge::GitHub, 'ubermuda/loupe', 1));
        $em->persist($card);

        return $project;
    }
}
