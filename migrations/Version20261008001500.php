<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008001500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add board_cards.source and its run columns, backfill them from the reporter, and retire the site-review card type';
    }

    /**
     * The run columns carry no foreign key, so a card keeps its source after
     * the run is deleted. The source stays nullable with no default, because an
     * image that predates it writes a card without one and the entity then reads
     * the reporter. A later release makes it NOT NULL.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD source VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD source_run_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD source_run_card_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE board_cards SET source = CASE COALESCE(reporter, origin)
                WHEN 'human' THEN 'person'
                WHEN 'reviewer' THEN 'widget'
                WHEN 'system' THEN 'loupe'
                ELSE 'agent'
            END
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE board_cards SET type = COALESCE(
                (SELECT definition->>'defaultType' FROM workflow_bindings WHERE workflow_bindings.project_id = board_cards.project_id),
                'feature'
            ) WHERE type = 'site-review' AND NOT EXISTS (
                SELECT 1 FROM workflow_bindings
                WHERE workflow_bindings.project_id = board_cards.project_id
                AND definition->'types' @> '[{"key": "site-review"}]'::jsonb
            )
            SQL);
    }

    /** The retired card type is not restored. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP source');
        $this->addSql('ALTER TABLE board_cards DROP source_run_id');
        $this->addSql('ALTER TABLE board_cards DROP source_run_card_id');
    }
}
