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

    /**
     * @contract-phase: the subject replaced card_id a release ago. The deployed image reads and writes
     * subject_type and subject_id only, and the trigger filled card_id for the image before it.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS work_requests_card_subject ON work_requests');
        $this->addSql('DROP TRIGGER IF EXISTS bridge_worker_runs_card_subject ON bridge_worker_runs');
        $this->addSql('DROP TRIGGER IF EXISTS bridge_worker_run_usage_card_subject ON bridge_worker_run_usage');
        $this->addSql('DROP FUNCTION IF EXISTS work_card_subject()');
        $this->addSql('ALTER TABLE work_requests DROP card_id');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP card_id');
        $this->addSql('ALTER TABLE bridge_worker_run_usage DROP card_id');
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
