<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261003185202 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Write the shipped copy of each workflow template into every binding of that template';
    }

    /** The engine reads the stored copy, so a project bound before this release gets the rules that the release adds. */
    public function up(Schema $schema): void
    {
        foreach (['lifecycle', 'simple'] as $key) {
            $source = Yaml::parseFile(\sprintf('%s/../config/workflows/%s.yaml', __DIR__, $key));
            if (!\is_array($source) || !\is_int($source['version'] ?? null)) {
                throw new \LogicException(\sprintf('The shipped workflow template "%s" has no version.', $key));
            }
            $this->addSql(
                'UPDATE workflow_bindings SET template_version = :version, definition = CAST(:definition AS JSONB) WHERE template_key = :key',
                ['key' => $key, 'version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
            );
        }
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
