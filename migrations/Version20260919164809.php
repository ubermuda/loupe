<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919164809 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Move forwards_to_agent from the widget token onto its project';
    }

    /**
     * The previous image still reads api_tokens.forwards_to_agent, so that
     * column stays. A later release drops it.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD forwards_to_agent BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE projects SET forwards_to_agent = t.forwards_to_agent FROM api_tokens t WHERE t.id = projects.widget_token_id');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE api_tokens SET forwards_to_agent = p.forwards_to_agent FROM projects p WHERE p.widget_token_id = api_tokens.id');
        $this->addSql('ALTER TABLE projects DROP forwards_to_agent');
    }
}
