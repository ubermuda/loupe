<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261010200634 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped Lifecycle copy, with its new nothing-to-build and delivered-without-code rules, into every Lifecycle binding, and keep the epic branch of each copy';
    }

    /** The engine reads the stored copy, so a project bound before this release lacks the two rules that finish a card with no code. The epic branch belongs to the project, so each copy keeps its own, or keeps none. */
    public function up(Schema $schema): void
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
            throw new \LogicException('The shipped workflow template "lifecycle" has no version.');
        }
        $this->addSql(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = :version,
                    definition = CASE
                        WHEN definition->'epicBranch' IS NULL THEN CAST(:definition AS JSONB) - 'epicBranch'
                        ELSE CAST(:definition AS JSONB) || jsonb_build_object('epicBranch', definition->'epicBranch')
                    END
                WHERE template_key = :key
                SQL,
            ['key' => 'lifecycle', 'version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        );
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
