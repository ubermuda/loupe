<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AnchorService used to write Anchor::$offsetHint as a byte offset into a
 * version's plain text and now writes a character offset. The two are equal for
 * ASCII, so this converts nothing on most installs — it exists so a database
 * that does carry multi-byte text is not left holding both units at once, which
 * misorders the comment sidebar and can pull a re-anchored quote to the wrong
 * occurrence.
 */
final class Version20260826205346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Convert comments.anchor_offset_hint from byte to character offsets';
    }

    public function up(Schema $schema): void
    {
        $this->reoffset(static fn (string $text, int $hint): int => mb_strlen(substr($text, 0, $hint), 'UTF-8'));
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->reoffset(static fn (string $text, int $hint): int => \strlen(mb_substr($text, 0, $hint, 'UTF-8')));
    }

    /**
     * Rewrites every anchored comment's offset hint against its OWN version's
     * plain text, since the same offset means a different position in each.
     *
     * The derivation is inlined rather than taken from DocumentVersion: a
     * migration has to keep meaning what it meant when it ran, and an entity is
     * free to change.
     *
     * @param callable(string, int): int $convert
     */
    private function reoffset(callable $convert): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.anchor_offset_hint AS hint, v.rendered_html AS html
             FROM comments c
             INNER JOIN document_versions v ON v.id = c.version_id
             WHERE c.anchor_offset_hint > 0',
        );

        foreach ($rows as $row) {
            $hint = (int) $row['hint'];
            $text = html_entity_decode(strip_tags((string) $row['html']), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $converted = $convert($text, $hint);

            if ($converted !== $hint) {
                $this->addSql(
                    'UPDATE comments SET anchor_offset_hint = :hint WHERE id = :id',
                    ['hint' => $converted, 'id' => (string) $row['id']],
                );
            }
        }
    }
}
