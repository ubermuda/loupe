<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726002828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.disabled_at and billing_profiles survey markers for the trial-end sweep.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_profiles ADD survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_profiles ADD cancel_survey_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD disabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_profiles DROP survey_sent_at');
        $this->addSql('ALTER TABLE billing_profiles DROP cancel_survey_sent_at');
        $this->addSql('ALTER TABLE users DROP disabled_at');
    }
}
