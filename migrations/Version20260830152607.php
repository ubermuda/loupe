<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830152607 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the subscriptions table, one row per grant of access';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscriptions (id UUID NOT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, stripe_status VARCHAR(20) DEFAULT NULL, survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, kind VARCHAR(20) NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, granted_by_id UUID DEFAULT NULL, billing_profile_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_4778A013151C11F ON subscriptions (granted_by_id)');
        $this->addSql('CREATE INDEX IDX_4778A01409D7D29 ON subscriptions (billing_profile_id)');
        $this->addSql('CREATE INDEX idx_subscriptions_kind_ends_at ON subscriptions (kind, ends_at)');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A013151C11F FOREIGN KEY (granted_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_4778A01409D7D29 FOREIGN KEY (billing_profile_id) REFERENCES billing_profiles (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A013151C11F');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT FK_4778A01409D7D29');
        $this->addSql('DROP TABLE subscriptions');
    }
}
