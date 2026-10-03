<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003142153 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Close the open notices about a bridge rule that races the app sync';
    }

    /** Only a bridge rule report opened a notice, and the app no longer reads those reports. */
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE inbox_items SET state = 'obsolete', closed_at = NOW(), updated_at = NOW() WHERE kind = 'notice' AND state = 'open'");
    }

    /** A closed notice stays closed. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
