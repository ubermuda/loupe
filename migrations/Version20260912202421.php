<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Two existing projects of one owner can give one slug, such as "My App" and
 * "my-app". The older keeps it, and each later one takes the first free suffix.
 * A name that gives no slug at all takes `project` under the same rule.
 * The column stays nullable until a later release, so a rollback keeps working.
 */
final class Version20260912202421 extends AbstractMigration
{
    public const string EMPTY_SLUG_FALLBACK = 'project';

    #[\Override]
    public function getDescription(): string
    {
        return 'Add a slug to projects, unique per owner, backfilled from the name';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, owner_id, name FROM projects ORDER BY owner_id, created_at, id',
        );

        $this->addSql('ALTER TABLE projects ADD slug TEXT DEFAULT NULL');
        foreach (self::assignSlugs($rows) as $id => $slug) {
            $this->addSql('UPDATE projects SET slug = :slug WHERE id = :id', ['slug' => $slug, 'id' => $id]);
        }
        // The previous image writes no slug, and Postgres treats NULLs as distinct.
        $this->addSql('CREATE UNIQUE INDEX uniq_project_owner_slug ON projects (owner_id, slug)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_project_owner_slug');
        $this->addSql('ALTER TABLE projects DROP slug');
    }

    /**
     * @param list<array<string, mixed>> $rows each with id, owner_id and name, oldest first within an owner
     *
     * @return array<string, string> the slug for each project id
     */
    public static function assignSlugs(array $rows): array
    {
        // Inlined rather than taken from App\Utils\Slug: a migration has to keep
        // meaning what it meant when it ran, and the app's rule may change.
        $slugger = new AsciiSlugger();
        $taken = [];
        $slugs = [];

        foreach ($rows as $row) {
            $owner = (string) $row['owner_id'];
            $base = $slugger->slug((string) $row['name'])->lower()->toString();
            if ('' === $base) {
                $base = self::EMPTY_SLUG_FALLBACK;
            }

            $slug = $base;
            for ($suffix = 2; isset($taken[$owner][$slug]); ++$suffix) {
                $slug = $base.'-'.$suffix;
            }

            $taken[$owner][$slug] = true;
            $slugs[(string) $row['id']] = $slug;
        }

        return $slugs;
    }
}
