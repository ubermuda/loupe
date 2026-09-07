<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests', []));

        $deleter->delete($doomed);
        $em->clear();

        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $doomedId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_card_pull_requests', []));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => $sparedId]));
    }

    private function seedBoard(EntityManagerInterface $em, User $owner, string $name): Project
    {
        $project = new Project($owner, $name.'-'.uniqid());
        $em->persist($project);

        $card = new Card(project: $project, title: 'Ship it', body: 'Body', number: 1);
        if ('doomed' === $name) {
            $card->pullRequests->add(new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/1', Forge::GitHub, 'ubermuda/loupe', 1));
        }
        $em->persist($card);

        return $project;
    }
}
