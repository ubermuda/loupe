<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830152647 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the superseded grant columns from billing_profiles';
    }

    /**
     * @contract-phase: the subscriptions table now holds every grant, and the
     *                 cut-over release stopped reading and writing these columns
     */
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_billing_profiles_status_trial_ends_at');
        $this->addSql('DROP INDEX idx_billing_profiles_status_current_period_end');
        $this->addSql('ALTER TABLE billing_profiles DROP status');
        $this->addSql('ALTER TABLE billing_profiles DROP stripe_subscription_id');
        $this->addSql('ALTER TABLE billing_profiles DROP current_period_end');
        $this->addSql('ALTER TABLE billing_profiles DROP trial_ends_at');
        $this->addSql('ALTER TABLE billing_profiles DROP survey_sent_at');
        $this->addSql('ALTER TABLE billing_profiles DROP cancel_survey_sent_at');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_profiles ADD status VARCHAR(20) DEFAULT \'trialing\' NOT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD stripe_subscription_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD cancel_survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_billing_profiles_status_trial_ends_at ON billing_profiles (status, trial_ends_at)');
        $this->addSql('CREATE INDEX idx_billing_profiles_status_current_period_end ON billing_profiles (status, current_period_end)');
    }
}
