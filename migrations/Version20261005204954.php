<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005204954 extends AbstractMigration
{
    private const array TABLES = ['work_requests', 'bridge_worker_runs', 'bridge_worker_run_usage'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Name the subject of work requests, worker runs and run usage, and stop using card_id';
    }

    public function up(Schema $schema): void
    {
        // The previous image writes card_id and no subject during a deploy or after a rollback, and reads card_id.
        // The trigger fills each side from the other until a later migration drops card_id.
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
            $this->addSql("ALTER TABLE {$table} ADD subject_type VARCHAR(40) DEFAULT 'card' NOT NULL, ADD subject_id UUID DEFAULT NULL");
            $this->addSql("UPDATE {$table} SET subject_id = card_id");
            $this->addSql("ALTER TABLE {$table} ALTER subject_type DROP DEFAULT, ALTER subject_id SET NOT NULL, ALTER card_id DROP NOT NULL");
            $this->addSql("CREATE TRIGGER {$table}_card_subject BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION work_card_subject()");
        }
        $this->addSql('ALTER TABLE work_requests ALTER card_number DROP NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER card_number DROP NOT NULL');

        $this->addSql('DROP INDEX uniq_work_request_live_card_kind');
        $this->addSql('DROP INDEX idx_work_requests_card');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_interactive_open');
        $this->addSql('DROP INDEX idx_bridge_worker_run_usage_card');

        $this->addSql("CREATE UNIQUE INDEX uniq_work_request_live_subject_kind ON work_requests (subject_type, subject_id, kind) WHERE ((state)::text = ANY (ARRAY[('open'::character varying)::text, ('claimed'::character varying)::text]))");
        $this->addSql('CREATE INDEX idx_work_requests_subject ON work_requests (subject_type, subject_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, subject_type, subject_id, started_at) WHERE ((run_key IS NULL) AND ((kind)::text = 'worker'::text))");
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_interactive_open ON bridge_worker_runs (project_id, subject_type, subject_id, session_id) WHERE (((kind)::text = 'interactive'::text) AND ((state)::text = 'running'::text))");
        $this->addSql('CREATE INDEX idx_bridge_worker_runs_subject ON bridge_worker_runs (project_id, subject_type, subject_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_usage_subject ON bridge_worker_run_usage (project_id, subject_type, subject_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bridge_worker_run_usage_subject');
        $this->addSql('DROP INDEX idx_bridge_worker_runs_subject');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_interactive_open');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql('DROP INDEX idx_work_requests_subject');
        $this->addSql('DROP INDEX uniq_work_request_live_subject_kind');

        // The old schema holds a card on every row, so a row about any other subject goes.
        foreach (self::TABLES as $table) {
            $this->addSql("DROP TRIGGER IF EXISTS {$table}_card_subject ON {$table}");
            $this->addSql("DELETE FROM {$table} WHERE subject_type <> 'card'");
            $this->addSql("UPDATE {$table} SET card_id = subject_id WHERE card_id IS NULL");
            $this->addSql("ALTER TABLE {$table} DROP subject_type, DROP subject_id, ALTER card_id SET NOT NULL");
        }
        $this->addSql('DROP FUNCTION IF EXISTS work_card_subject()');
        $this->addSql('DELETE FROM work_requests WHERE card_number IS NULL');
        $this->addSql('DELETE FROM bridge_worker_runs WHERE card_number IS NULL');
        $this->addSql('ALTER TABLE work_requests ALTER card_number SET NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER card_number SET NOT NULL');

        $this->addSql("CREATE UNIQUE INDEX uniq_work_request_live_card_kind ON work_requests (card_id, kind) WHERE ((state)::text = ANY (ARRAY[('open'::character varying)::text, ('claimed'::character varying)::text]))");
        $this->addSql('CREATE INDEX idx_work_requests_card ON work_requests (card_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at) WHERE ((run_key IS NULL) AND ((kind)::text = 'worker'::text))");
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_interactive_open ON bridge_worker_runs (project_id, card_id, session_id) WHERE (((kind)::text = 'interactive'::text) AND ((state)::text = 'running'::text))");
        $this->addSql('CREATE INDEX idx_bridge_worker_run_usage_card ON bridge_worker_run_usage (project_id, card_id)');
    }
}
