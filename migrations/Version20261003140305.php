<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003140305 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop each stored workflow rule that reads the removed pr.all_closed_unmerged condition';
    }

    /** The parser refuses an unknown condition, so a stored copy that still names it fails to load. */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE workflow_bindings SET definition = jsonb_set(definition, '{rules}', (
                SELECT COALESCE(jsonb_agg(rule ORDER BY position), '[]'::jsonb)
                FROM jsonb_array_elements(definition->'rules') WITH ORDINALITY AS rules(rule, position)
                WHERE strpos(rule::text, '"pr.all_closed_unmerged"') = 0
            ))
            WHERE strpos((definition->'rules')::text, '"pr.all_closed_unmerged"') > 0
            SQL);
    }

    /** The dropped rules name a condition that no longer exists, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
