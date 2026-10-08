<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008122922 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the kind to the worker run tool calls, and tag the stored calls by their tool name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_tool_calls ADD kind VARCHAR(20) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE bridge_worker_run_tool_calls SET kind = CASE
                WHEN tool = 'Bash' THEN 'shell'
                WHEN tool IN ('Agent', 'Task') THEN 'subagent'
                ELSE 'tool'
            END
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_tool_calls DROP kind');
    }
}
