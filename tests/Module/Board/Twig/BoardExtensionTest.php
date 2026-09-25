<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Twig\BoardExtension;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BoardExtensionTest extends KernelTestCase
{
    public function test_card_digest_changes_when_the_card_takes_another_rank_in_its_column(): void
    {
        $extension = static::getContainer()->get(BoardExtension::class);
        self::assertInstanceOf(BoardExtension::class, $extension);
        $card = $this->makeCard();

        $card->position = 2;
        $before = $extension->cardDigest($card, 0);
        self::assertSame($before, $extension->cardDigest($card, 0));

        $card->position = 0;
        self::assertNotSame($before, $extension->cardDigest($card, 0));
    }

    private function makeCard(): Card
    {
        $project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');

        return new Card(project: $project, column: new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0), title: 'Ship the board', body: 'Body', number: 1);
    }
}
