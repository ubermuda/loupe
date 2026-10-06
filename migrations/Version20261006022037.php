<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006022037 extends AbstractMigration
{
    private const array TABLES = ['work_requests', 'bridge_worker_runs', 'bridge_worker_run_usage'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Drop card_id from work requests, worker runs and run usage, with the trigger that filled it';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql("DROP TRIGGER IF EXISTS {$table}_card_subject ON {$table}");
            $this->addSql("ALTER TABLE {$table} DROP card_id");
        }
        $this->addSql('DROP FUNCTION IF EXISTS work_card_subject()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE FUNCTION work_card_subject() RETURNS trigger AS $$
            BEGIN
                IF NEW.subject_id IS NULL THEN
                    NEW.subject_type := 'card';
                    NEW.subject_id := NEW.card_id;
                ELSIF NEW.card_id IS NULL AND NEW.subject_type = 'card' THEN
                    NEW.card_id := NEW.subject_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (self::TABLES as $table) {
            $this->addSql("ALTER TABLE {$table} ADD card_id UUID DEFAULT NULL");
            $this->addSql("UPDATE {$table} SET card_id = subject_id WHERE subject_type = 'card'");
            $this->addSql("CREATE TRIGGER {$table}_card_subject BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION work_card_subject()");
        }
    }
}
