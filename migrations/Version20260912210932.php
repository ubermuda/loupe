<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260912210932 extends AbstractMigration
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
        return 'Seed columns for projects without any, re-derive board_cards.column_id, and index the board by column';
    }

    /**
     * Release 2 of the move from the status enum to column rows. The image
     * before columns could still create a project or move a card after the
     * first migration ran, so the seed and the backfill run again, for every
     * row. `status` and its index stay, because the previous image reads them,
     * and `column_id` stays nullable until the release that drops `status`.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        /** @var list<string> $projectIds */
        $projectIds = $this->connection->fetchFirstColumn(
            'SELECT p.id FROM projects p WHERE NOT EXISTS (SELECT 1 FROM board_columns k WHERE k.project_id = p.id) ORDER BY p.id',
        );
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

        $this->addSql('UPDATE board_cards SET column_id = k.id FROM board_columns k WHERE k.project_id = board_cards.project_id AND k.slug = board_cards.status AND board_cards.column_id IS DISTINCT FROM k.id');
        $this->addSql('CREATE INDEX idx_board_cards_column_order ON board_cards (column_id, priority, position)');
    }

    /** The seeded columns and the backfilled ids stay, because the previous image writes them too. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_column_order');
    }
}
