<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007150100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the last work request and the repaired flag to the workflow rule states';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD work_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_rule_states ADD repaired BOOLEAN DEFAULT false NOT NULL');
        // A request in flight at the deploy still reaches the retry and the pause when it settles as refused.
        $this->addSql(<<<'SQL'
            UPDATE workflow_rule_states s SET work_request_id = (
                SELECT w.id FROM work_requests w
                WHERE w.subject_type = 'card' AND w.subject_id = s.card_id AND w.rule_id = s.rule_id
                    AND w.state IN ('open', 'claimed')
                ORDER BY w.created_at DESC, w.id DESC LIMIT 1
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP work_request_id');
        $this->addSql('ALTER TABLE workflow_rule_states DROP repaired');
    }
}
