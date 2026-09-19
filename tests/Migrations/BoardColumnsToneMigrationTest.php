<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260918234851;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260918234851.php';

/** Every existing column keeps the colour its role and position gave it. */
final class BoardColumnsToneMigrationTest extends KernelTestCase
{
    public function test_the_backfill_writes_the_colour_each_column_showed_before(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();

        $owner = new User(fullName: 'Riley', email: 'board-columns-tone-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'Tones-'.uniqid());
        $em->persist($owner);
        $em->persist($project);
        foreach ([['backlog', 0, false, true], ['ready', 1, false, false], ['doing', 2, false, false], ['review', 3, false, false], ['qa', 4, false, false], ['done', 5, true, false]] as [$slug, $position, $terminal, $isDefault]) {
            $em->persist(new BoardColumn($project, $slug, $slug, $position, $terminal, $isDefault));
        }
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $migration = new Version20260918234851($connection, new NullLogger());
        $migration->down(new Schema());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame(
            ['backlog' => 'neutral', 'ready' => 'lime', 'doing' => 'purple', 'review' => 'amber', 'qa' => 'lime', 'done' => 'green'],
            $connection->fetchAllKeyValue('SELECT slug, tone FROM board_columns WHERE project_id = :id ORDER BY position', ['id' => $projectId]),
        );
    }
}
