<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726190341 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add composite indexes for the billing trial sweep candidate queries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_billing_profiles_status_trial_ends_at ON billing_profiles (status, trial_ends_at)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_billing_profiles_status_current_period_end ON billing_profiles (status, current_period_end)
        SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_billing_profiles_status_trial_ends_at');
        $this->addSql('DROP INDEX idx_billing_profiles_status_current_period_end');
    }
}
