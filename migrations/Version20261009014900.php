<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009014900 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the submission id to card verdicts so a retried Send finds the saved verdict';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_verdicts ADD submission_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_verdicts_card_submission ON board_card_verdicts (card_id, submission_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_board_card_verdicts_card_submission');
        $this->addSql('ALTER TABLE board_card_verdicts DROP submission_id');
    }
}
