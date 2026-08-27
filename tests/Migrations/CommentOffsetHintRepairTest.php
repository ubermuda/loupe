<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260826205346;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

// Migrations are deliberately not autoloaded (see config/packages/doctrine_migrations.yaml),
// and this one carries arithmetic that would otherwise only ever run on a customer's data.
require_once __DIR__.'/../../migrations/Version20260826205346.php';

/**
 * The document's plain text puts two multi-byte characters before the quote, so
 * its byte offset and its character offset differ — which is the only condition
 * under which either a repair or a corruption is visible at all.
 */
final class CommentOffsetHintRepairTest extends KernelTestCase
{
    private const string HTML = '<p>Café — we will rotate the key hourly.</p>';
    private const string QUOTE = 'rotate the key';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_a_hint_already_in_characters_is_left_where_it_is(): void
    {
        $characters = $this->characterOffset();
        // Guard: without a difference between the two units the assertion below
        // passes on a migration that converts every row it sees.
        self::assertNotSame($this->byteOffset(), $characters);

        $id = $this->comment('hint-characters@example.com', $characters);
        $this->runMigration();

        self::assertSame($characters, $this->storedHint($id));
    }

    public function test_a_hint_left_in_bytes_is_moved_onto_the_character_it_names(): void
    {
        $id = $this->comment('hint-bytes@example.com', $this->byteOffset());
        $this->runMigration();

        self::assertSame($this->characterOffset(), $this->storedHint($id));
    }

    public function test_a_hint_neither_reading_explains_is_left_alone(): void
    {
        // The quote is gone from this version's text, so no arithmetic can place
        // it — repairing that is the reanchor command's job, not this migration's.
        $id = $this->comment('hint-unplaceable@example.com', 7, 'a passage since deleted');
        $this->runMigration();

        self::assertSame(7, $this->storedHint($id));
    }

    private function runMigration(): void
    {
        $this->em->flush();

        $migration = new Version20260826205346($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function characterOffset(): int
    {
        $at = mb_strpos($this->plainText(), self::QUOTE, 0, 'UTF-8');
        self::assertIsInt($at);

        return $at;
    }

    private function byteOffset(): int
    {
        $at = strpos($this->plainText(), self::QUOTE);
        self::assertIsInt($at);

        return $at;
    }

    private function plainText(): string
    {
        return html_entity_decode(strip_tags(self::HTML), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** Reads the raw column, so the ORM's identity map cannot answer with the pre-migration value. */
    private function storedHint(string $id): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT anchor_offset_hint FROM comments WHERE id = :id',
            ['id' => $id],
        );
    }

    /** @param non-empty-string $email */
    private function comment(string $email, int $offsetHint, string $quote = self::QUOTE): string
    {
        $author = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($author);

        $project = new Project($author, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $author, project: $project, title: 'Key rotation');
        $document->addVersion('# Key rotation', self::HTML);
        $this->em->persist($document);

        $comment = new Comment(
            $document->currentVersion(),
            $author,
            'Worth a look.',
            new Anchor($quote, 'will ', ' hourly', $offsetHint),
        );
        $this->em->persist($comment);
        $this->em->flush();

        $id = $comment->id;
        self::assertNotNull($id);

        return (string) $id;
    }
}
