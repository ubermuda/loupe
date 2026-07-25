<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725152500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing_profiles table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_profiles (id UUID NOT NULL, status VARCHAR(20) NOT NULL, stripe_customer_id VARCHAR(255) DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_stripe_event_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, trial_ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_93ECECCCA76ED395 ON billing_profiles (user_id)');
        $this->addSql('ALTER TABLE billing_profiles ADD CONSTRAINT FK_93ECECCCA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_profiles DROP CONSTRAINT FK_93ECECCCA76ED395');
        $this->addSql('DROP TABLE billing_profiles');
    }
}
