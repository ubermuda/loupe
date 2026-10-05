<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261005184706 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename the Lifecycle stage tags product and design to product-design and tech-design, in every binding and on every document';
    }

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

        // A product design that also carries design must not count as a tech design.
        $this->addSql(
            "DELETE FROM document_tags d USING tags t
              WHERE d.tag_id = t.id AND t.name = 'design'
                AND EXISTS (SELECT 1 FROM document_tags p JOIN tags tp ON tp.id = p.tag_id WHERE p.document_id = d.document_id AND tp.name = 'product')",
        );

        $this->addSql(
            "DELETE FROM tags t WHERE t.name IN ('product', 'design')
                AND NOT EXISTS (SELECT 1 FROM document_tags d WHERE d.tag_id = t.id)",
        );

        foreach (['product' => 'product-design', 'design' => 'tech-design'] as $old => $new) {
            $names = ['old' => $old, 'new' => $new];
            $this->addSql(
                'INSERT INTO document_tags (document_id, tag_id)
                 SELECT d.document_id, n.id FROM document_tags d
                   JOIN tags o ON o.id = d.tag_id AND o.name = :old
                   JOIN tags n ON n.project_id = o.project_id AND n.name = :new
                 ON CONFLICT DO NOTHING',
                $names,
            );
            $this->addSql(
                'DELETE FROM document_tags d USING tags o, tags n
                  WHERE d.tag_id = o.id AND o.name = :old AND n.project_id = o.project_id AND n.name = :new',
                $names,
            );
            $this->addSql('DELETE FROM tags o USING tags n WHERE o.name = :old AND n.project_id = o.project_id AND n.name = :new', $names);
            $this->addSql('UPDATE tags SET name = :new WHERE name = :old', $names);
        }

        // A false truth makes the next sweep see an edge, so a card the old tags held moves.
        $this->addSql(
            "UPDATE workflow_rule_states SET truth = false
              WHERE rule_id IN ('product-design-approved', 'tech-design-approved')
                AND project_id IN (SELECT project_id FROM workflow_bindings WHERE template_key = :key)",
            ['key' => 'lifecycle'],
        );
    }

    /** The old tags are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
