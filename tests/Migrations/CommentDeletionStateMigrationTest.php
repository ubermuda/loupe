<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260916200611;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260916200611.php';

final class CommentDeletionStateMigrationTest extends KernelTestCase
{
    public function test_existing_threads_keep_their_data_and_start_undeleted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Reviewer', email: 'deletion-migration@example.com');
        $project = new Project($owner, 'deletion-migration');
        $document = new Document($owner, $project, 'Review');
        $version = $document->addVersion('Passage', '<p>Passage</p>');
        $root = new Comment($version, $owner, 'Root body', new Anchor('Passage', '', '', 0), replacement: 'Replacement');
        $root->status = CommentStatus::Resolved;
        $reply = new Comment($version, $owner, 'Reply body', $root->anchor, $root);
        foreach ([$owner, $project, $document, $root, $reply] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();
        $connection = $em->getConnection();
        $select = 'SELECT id, parent_id, version_id, author_id, body, status, replacement, anchor_quote FROM comments ORDER BY id';
        $original = $connection->fetchAllAssociative($select);
        self::assertCount(2, $original);

        $down = new Version20260916200611($connection, new NullLogger());
        $down->down(new Schema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $up = new Version20260916200611($connection, new NullLogger());
        $up->up(new Schema());
        foreach ($up->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame($original, $connection->fetchAllAssociative($select));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM comments WHERE deleted_at IS NULL AND deletion_sequence = 0'));
    }
}
