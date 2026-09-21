<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921213048 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create oauth_credentials and release the audit trail from api_tokens';
    }

    /**
     * Expand only, so a rollback across this release still runs. The generated
     * diff also dropped api_tokens, the two project token columns and the
     * audit_log foreign key over to the new table. Each of those is a contract
     * under docs/operating/migrations.md, so each waits for its own release:
     * the previous image still writes an api_tokens id into credential_id, and
     * a foreign key to oauth_credentials would refuse it.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_credentials (id UUID NOT NULL, handle VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A62484A5918020D9 ON oauth_credentials (handle)');
        $this->addSql('CREATE INDEX IDX_A62484A57E3C61F9 ON oauth_credentials (owner_id)');
        $this->addSql('ALTER TABLE oauth_credentials ADD CONSTRAINT FK_A62484A57E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        // @contract-phase: nothing reads audit_log.credential_id. The column is
        // written at insert and read only by the account purger, which now
        // matches oauth_credentials. The previous image keeps writing an
        // api_tokens id, and a foreign key to the new table would refuse it, so
        // the old key goes now and the new one waits for board card 178.
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT fk_f6e1c0f52558a7a5');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM audit_log WHERE credential_id IS NOT NULL');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT fk_f6e1c0f52558a7a5 FOREIGN KEY (credential_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE oauth_credentials DROP CONSTRAINT FK_A62484A57E3C61F9');
        $this->addSql('DROP TABLE oauth_credentials');
    }
}
