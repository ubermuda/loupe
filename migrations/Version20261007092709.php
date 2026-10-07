<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261007092709 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle copy, whose child-unblocked rule waits for any worker run of the parent epic, whose breakdown-ended and merged rules read any run, and whose manual moves let a run of the parent move a child, into every Lifecycle binding';
    }

    /** The engine reads the stored copy, so a project bound before this release gets the new rules. */
    public function up(Schema $schema): void
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
            throw new \LogicException('The shipped workflow template "lifecycle" has no version.');
        }
        $this->addSql(
            'UPDATE workflow_bindings SET template_version = :version, definition = CAST(:definition AS JSONB) WHERE template_key = :key',
            ['key' => 'lifecycle', 'version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
