<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009023627 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the start times of the pull request problems and of the blocker hold';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD checks_failed_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD conflicting_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD waits_for_approval_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_rule_states ADD held_by_blocker_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP checks_failed_since');
        $this->addSql('ALTER TABLE forge_pull_requests DROP conflicting_since');
        $this->addSql('ALTER TABLE forge_pull_requests DROP waits_for_approval_since');
        $this->addSql('ALTER TABLE workflow_rule_states DROP held_by_blocker_since');
    }
}
