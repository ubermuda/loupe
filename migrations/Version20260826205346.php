<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AnchorService used to write Anchor::$offsetHint as a byte offset into a
 * version's plain text and now writes a character offset. Nothing on the row
 * says which unit it holds, and a database can carry both — so this repairs by
 * verification rather than by conversion: a hint is rewritten only when the
 * quote is NOT where it currently points and IS where reading it as a byte
 * offset would put it.
 *
 * A row that is already correct therefore cannot be moved, whichever unit wrote
 * it, and a row neither reading explains — an orphaned comment, a version
 * revised underneath it — is left for `app:review:rerender-versions --reanchor`.
 */
final class Version20260826205346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Repair comments.anchor_offset_hint rows still holding a byte offset';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.anchor_offset_hint AS hint, c.anchor_quote AS quote, v.rendered_html AS html
             FROM comments c
             INNER JOIN document_versions v ON v.id = c.version_id
             WHERE c.anchor_offset_hint > 0 AND c.anchor_quote <> \'\'',
        );

        foreach ($rows as $row) {
            $hint = (int) $row['hint'];
            $quote = (string) $row['quote'];
            // Inlined rather than taken from DocumentVersion: a migration has to
            // keep meaning what it meant when it ran, and an entity may change.
            $text = html_entity_decode(strip_tags((string) $row['html']), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

            if ($this->holdsQuote($text, $hint, $quote)) {
                continue;
            }

            $asCharacters = mb_strlen(substr($text, 0, $hint), 'UTF-8');
            if (!$this->holdsQuote($text, $asCharacters, $quote)) {
                continue;
            }

            $this->addSql(
                'UPDATE comments SET anchor_offset_hint = :hint WHERE id = :id',
                ['hint' => $asCharacters, 'id' => (string) $row['id']],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Nothing to undo: up() only rewrote hints that pointed at the wrong span,
        // and which rows those were is not recorded anywhere to put back.
    }

    /** Whether $text really does read as $quote from character offset $at. */
    private function holdsQuote(string $text, int $at, string $quote): bool
    {
        return mb_substr($text, $at, mb_strlen($quote, 'UTF-8'), 'UTF-8') === $quote;
    }
}
