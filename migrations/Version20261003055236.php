<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261003055236 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Bind every project to a workflow template by its column slugs, and baseline every card before the engine runs';
    }

    /**
     * A board with an open column for each Lifecycle slot slug gets Lifecycle, linked by slug. Every other board gets Simple.
     * The stored definition is the parsed YAML file, which is what BindWorkflowTemplateHandler stores.
     */
    public function up(Schema $schema): void
    {
        $lifecycle = self::template('lifecycle');
        $simple = self::template('simple');
        $slots = $lifecycle['slotKeys'];
        $lifecycleBoard = <<<'SQL'
            NOT EXISTS (SELECT 1 FROM workflow_bindings b WHERE b.project_id = p.id)
            AND (SELECT COUNT(*) FROM board_columns c
                 WHERE c.project_id = p.id AND c.slug IN (:slots) AND NOT c.is_default AND NOT c.terminal) = :slotCount
            SQL;
        $params = ['slots' => $slots, 'slotCount' => \count($slots)];
        $types = ['slots' => ArrayParameterType::STRING];

        $this->addSql(<<<SQL
            INSERT INTO workflow_slot_links (id, project_id, slot_key, column_id)
            SELECT gen_random_uuid(), p.id, c.slug, c.id
            FROM projects p JOIN board_columns c ON c.project_id = p.id AND c.slug IN (:slots)
            WHERE {$lifecycleBoard}
            ON CONFLICT (project_id, slot_key) DO NOTHING
            SQL, $params, $types);
        $this->addSql(<<<SQL
            INSERT INTO workflow_bindings (id, project_id, template_key, template_version, definition, bound_at)
            SELECT gen_random_uuid(), p.id, :key, :version, CAST(:definition AS JSONB), LOCALTIMESTAMP(0)
            FROM projects p
            WHERE {$lifecycleBoard}
            SQL, [...$params, ...$lifecycle['params']], $types);
        $this->addSql(<<<'SQL'
            INSERT INTO workflow_bindings (id, project_id, template_key, template_version, definition, bound_at)
            SELECT gen_random_uuid(), p.id, :key, :version, CAST(:definition AS JSONB), LOCALTIMESTAMP(0)
            FROM projects p
            WHERE NOT EXISTS (SELECT 1 FROM workflow_bindings b WHERE b.project_id = p.id)
            SQL, $simple['params']);
        // A project bound before this ran its engine never, so its cards hold no rule memory either.
        $this->addSql(<<<'SQL'
            INSERT INTO workflow_pending_baselines (id, card_id, project_id, created_at)
            SELECT gen_random_uuid(), c.id, c.project_id, LOCALTIMESTAMP(0) FROM board_cards c
            ON CONFLICT (card_id) DO NOTHING
            SQL);
    }

    /** The rows this inserts look the same as the rows a person makes, so they stay. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }

    /** @return array{slotKeys: list<string>, params: array{key: string, version: int, definition: string}} */
    private static function template(string $key): array
    {
        $source = Yaml::parseFile(\sprintf('%s/../config/workflows/%s.yaml', __DIR__, $key));
        if (!\is_array($source) || !\is_string($source['key'] ?? null) || !\is_int($source['version'] ?? null) || !\is_array($source['slots'] ?? null)) {
            throw new \LogicException(\sprintf('The shipped workflow template "%s" has no key, version or slots.', $key));
        }
        $slotKeys = [];
        foreach ($source['slots'] as $slot) {
            $slotKeys[] = \is_array($slot) && \is_string($slot['key'] ?? null) ? $slot['key'] : throw new \LogicException(\sprintf('A slot of the shipped workflow template "%s" has no key.', $key));
        }

        return [
            'slotKeys' => $slotKeys,
            'params' => ['key' => $source['key'], 'version' => $source['version'], 'definition' => json_encode($source, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION)],
        ];
    }
}
