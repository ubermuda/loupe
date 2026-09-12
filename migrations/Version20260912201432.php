<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260912201432 extends AbstractMigration
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
        return 'Create board_columns, seed four columns per project, and backfill board_cards.column_id';
    }

    /**
     * Release 1 of the move from the status enum to column rows, and it only
     * expands. `status` and its index stay, because the deployed image reads
     * them. Postgres 16 has no uuidv7 function, so the ids are made here.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_columns (id UUID NOT NULL, label VARCHAR(100) NOT NULL, slug VARCHAR(255) NOT NULL, position INT NOT NULL, terminal BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E4D0E8B8166D1F9C ON board_columns (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_columns_project_slug ON board_columns (project_id, slug)');
        $this->addSql('ALTER TABLE board_columns ADD CONSTRAINT FK_E4D0E8B8166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');

        /** @var list<string> $projectIds */
        $projectIds = $this->connection->fetchFirstColumn('SELECT id FROM projects ORDER BY id');
        foreach ($projectIds as $projectId) {
            $rows = [];
            $parameters = [];
            foreach (self::COLUMNS as $slug => [$position, $terminal, $isDefault]) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $parameters,
                    Uuid::v7()->toRfc4122(),
                    $projectId,
                    'board.card.status.'.$slug,
                    $slug,
                    $position,
                    $terminal ? 'true' : 'false',
                    $isDefault ? 'true' : 'false',
                );
            }
            $this->addSql(
                'INSERT INTO board_columns (id, project_id, label, slug, position, terminal, is_default) VALUES '.implode(', ', $rows),
                $parameters,
            );
        }

        $this->addSql('ALTER TABLE board_cards ADD column_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD CONSTRAINT FK_A67FBFD6BE8E8ED5 FOREIGN KEY (column_id) REFERENCES board_columns (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A67FBFD6BE8E8ED5 ON board_cards (column_id)');
        $this->addSql('UPDATE board_cards SET column_id = k.id FROM board_columns k WHERE k.project_id = board_cards.project_id AND k.slug = board_cards.status');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP CONSTRAINT FK_A67FBFD6BE8E8ED5');
        $this->addSql('DROP INDEX IDX_A67FBFD6BE8E8ED5');
        $this->addSql('ALTER TABLE board_cards DROP column_id');
        $this->addSql('ALTER TABLE board_columns DROP CONSTRAINT FK_E4D0E8B8166D1F9C');
        $this->addSql('DROP TABLE board_columns');
    }
}
