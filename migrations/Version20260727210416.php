<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727210416 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add opt-in agent forwarding to API tokens and mark site-review events forwardable.';
    }

    public function up(Schema $schema): void
    {
        // Existing widget tokens are swept into the collect-only default with
        // everything else: the token sits in public page markup, so forwarding
        // must be something an owner turns on knowingly, including on tokens
        // minted before the flag existed. The visible effect on an existing
        // install is that submitted reviews stop nudging the agent until the
        // owner opts in on the project's connect page; the reviews themselves
        // still arrive, and the agent still reads them with site_review_get.
        $this->addSql('ALTER TABLE api_tokens ADD forwards_to_agent BOOLEAN DEFAULT false NOT NULL');

        // Events already in the table were written under the old always-forward
        // behaviour, so true is their accurate historical value.
        $this->addSql('ALTER TABLE site_review_events ADD forwardable BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP forwards_to_agent');
        $this->addSql('ALTER TABLE site_review_events DROP forwardable');
    }
}
