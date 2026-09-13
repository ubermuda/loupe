<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260912201847 extends AbstractMigration
{
    /** slug => [position, terminal, is_default], in board order. */
    private const array COLUMNS = [
        'backlog' => [0, false, true],
        'next' => [1, false, false],
        'in-progress' => [2, false, false],
        'done' => [3, true, false],
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Seed four board columns per project and backfill board_cards.column_id';
    }

    /**
     * Each statement commits alone, so no project row stays locked for the whole
     * backfill. Every statement is safe to run again after a failure part way.
     */
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    /** Postgres 16 has no uuidv7 function, so the ids are made here. */
    #[\Override]
    public function up(Schema $schema): void
    {
        /** @var list<string> $projectIds */
        $projectIds = $this->connection->fetchFirstColumn('SELECT id FROM projects ORDER BY id');
        foreach ($projectIds as $projectId) {
            $rows = [];
            $parameters = [];
            foreach (self::COLUMNS as $slug => [$position, $terminal, $isDefault]) {
                $rows[] = '(?, ?, ?, ?, ?, ?)';
                array_push(
                    $parameters,
                    Uuid::v7()->toRfc4122(),
                    'board.card.status.'.$slug,
                    $slug,
                    (string) $position,
                    $terminal ? 'true' : 'false',
                    $isDefault ? 'true' : 'false',
                );
            }
            $parameters[] = $projectId;
            // Joined on projects, so a project deleted since the SELECT above gets no rows.
            $this->addSql(
                'INSERT INTO board_columns (id, project_id, label, slug, position, terminal, is_default)
                 SELECT CAST(v.id AS UUID), p.id, v.label, v.slug, CAST(v.position AS INT), CAST(v.terminal AS BOOLEAN), CAST(v.is_default AS BOOLEAN)
                 FROM projects p CROSS JOIN (VALUES '.implode(', ', $rows).') AS v (id, label, slug, position, terminal, is_default)
                 WHERE p.id = ?
                 ON CONFLICT (project_id, slug) DO NOTHING',
                $parameters,
            );
        }

        $this->addSql('UPDATE board_cards SET column_id = k.id FROM board_columns k WHERE k.project_id = board_cards.project_id AND k.slug = board_cards.status AND board_cards.column_id IS NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_A67FBFD6BE8E8ED5 ON board_cards (column_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_A67FBFD6BE8E8ED5');
        $this->addSql('UPDATE board_cards SET column_id = NULL');
        $this->addSql('DELETE FROM board_columns');
    }
}
