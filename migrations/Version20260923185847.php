<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923185847 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Give worker runs a state, a run key and a state history';
    }

    public function up(Schema $schema): void
    {
        // The previous image writes no state during a deploy or after a rollback. The trigger below corrects its rows.
        $this->addSql("ALTER TABLE bridge_worker_runs ADD state VARCHAR(20) DEFAULT 'failed' NOT NULL");
        $this->addSql('ALTER TABLE bridge_worker_runs ADD run_key UUID DEFAULT NULL');
        $this->addSql("UPDATE bridge_worker_runs SET state = CASE WHEN exit_code IS NULL THEN 'not-started' WHEN exit_code = 0 AND has_result IS FALSE THEN 'no-result' WHEN exit_code = 0 THEN 'succeeded' ELSE 'failed' END");
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER started_at DROP NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER ended_at DROP NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER session_id DROP NOT NULL');
        $this->addSql('CREATE INDEX idx_bridge_worker_runs_state ON bridge_worker_runs (state)');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at) WHERE (run_key IS NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_key ON bridge_worker_runs (project_id, bridge_id, run_key)');

        $this->addSql('CREATE TABLE bridge_worker_run_states (id UUID NOT NULL, sequence BIGINT GENERATED ALWAYS AS IDENTITY NOT NULL, state VARCHAR(20) NOT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5B6BE21D5286D72B ON bridge_worker_run_states (sequence)');
        $this->addSql('CREATE INDEX IDX_5B6BE21D84E3FEC4 ON bridge_worker_run_states (run_id)');
        $this->addSql('ALTER TABLE bridge_worker_run_states ADD CONSTRAINT FK_5B6BE21D84E3FEC4 FOREIGN KEY (run_id) REFERENCES bridge_worker_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Every run so far reported once, when it ended, so it gets the rows the old report writes today:
        // a start for a process that ran, then the outcome.
        $this->addSql("INSERT INTO bridge_worker_run_states (id, run_id, state, at, received_at) SELECT gen_random_uuid(), id, 'running', started_at, received_at FROM bridge_worker_runs WHERE exit_code IS NOT NULL");
        $this->addSql('INSERT INTO bridge_worker_run_states (id, run_id, state, at, received_at) SELECT gen_random_uuid(), id, state, ended_at, received_at FROM bridge_worker_runs');

        // The previous image writes no state, so the default would call its every run failed.
        // This image writes failed only with an exit code other than 0, so the trigger leaves its rows alone.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION bridge_worker_runs_legacy_state() RETURNS trigger AS $$
            BEGIN
                IF NEW.run_key IS NULL AND NEW.state = 'failed' AND (NEW.exit_code IS NULL OR NEW.exit_code = 0) THEN
                    NEW.state := CASE
                        WHEN NEW.exit_code IS NULL THEN 'not-started'
                        WHEN NEW.has_result IS FALSE THEN 'no-result'
                        ELSE 'succeeded'
                    END;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->addSql('CREATE TRIGGER bridge_worker_runs_legacy_state BEFORE INSERT ON bridge_worker_runs FOR EACH ROW EXECUTE FUNCTION bridge_worker_runs_legacy_state()');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS bridge_worker_runs_legacy_state ON bridge_worker_runs');
        $this->addSql('DROP FUNCTION IF EXISTS bridge_worker_runs_legacy_state()');
        $this->addSql('DROP TABLE bridge_worker_run_states');
        // The old columns hold no run that never started or never ended.
        $this->addSql('DELETE FROM bridge_worker_runs WHERE started_at IS NULL OR ended_at IS NULL OR session_id IS NULL');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_key');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql('DROP INDEX idx_bridge_worker_runs_state');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP state');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP run_key');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER session_id SET NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER started_at SET NOT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER ended_at SET NOT NULL');
        // Keyed runs may share a card and a start second, which the old index refuses.
        $this->addSql('DELETE FROM bridge_worker_runs a USING bridge_worker_runs b WHERE a.project_id = b.project_id AND a.bridge_id = b.bridge_id AND a.card_id = b.card_id AND a.started_at = b.started_at AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at)');
    }
}
