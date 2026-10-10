<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20261009191142 extends AbstractMigration
{
    private const array RULE_IDS = ['comment-fix-run', 'comment-stale-approval'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Insert the comment-fix-run and comment-stale-approval rules of the shipped Lifecycle copy after the fix-in-review rule of every Lifecycle binding';
    }

    /** The engine reads the stored copy, so each copy gets the two rules, and the rest of the copy stays as it is. */
    public function up(Schema $schema): void
    {
        $shipped = $this->shippedRules();
        $bindings = $this->connection->fetchAllAssociative('SELECT id, definition FROM workflow_bindings WHERE template_key = :key', ['key' => 'lifecycle']);
        foreach ($bindings as $binding) {
            $definition = json_decode((string) $binding['definition'], true, flags: \JSON_THROW_ON_ERROR);
            $rules = \is_array($definition) ? ($definition['rules'] ?? null) : null;
            if (!\is_array($rules)) {
                continue;
            }
            $ids = array_map(static fn (mixed $rule): mixed => \is_array($rule) ? ($rule['id'] ?? null) : null, $rules);
            $missing = array_values(array_filter(self::RULE_IDS, static fn (string $id): bool => !\in_array($id, $ids, true)));
            if ([] === $missing) {
                continue;
            }

            $anchor = array_search('fix-in-review', $ids, true);
            $position = \is_int($anchor) ? $anchor : 0;
            $after = \is_int($anchor) ? 'true' : 'false';
            // Each insert goes to the same place, so the last rule goes in first.
            $expression = 'definition';
            $parameters = ['id' => $binding['id']];
            foreach (array_reverse($missing) as $index => $id) {
                $expression = \sprintf("jsonb_insert(%s, '{rules,%d}', CAST(:rule%d AS JSONB), %s)", $expression, $position, $index, $after);
                $parameters['rule'.$index] = json_encode($shipped[$id], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
            }
            $this->addSql('UPDATE workflow_bindings SET definition = '.$expression.' WHERE id = :id', $parameters);
        }
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }

    /** @return array<string, mixed> each new rule of the shipped copy, by id */
    private function shippedRules(): array
    {
        $source = Yaml::parseFile(__DIR__.'/../config/workflows/lifecycle.yaml');
        $rules = \is_array($source) && \is_array($source['rules'] ?? null) ? $source['rules'] : [];
        $shipped = [];
        foreach ($rules as $rule) {
            if (\is_array($rule) && \in_array($rule['id'] ?? null, self::RULE_IDS, true)) {
                $shipped[$rule['id']] = $rule;
            }
        }
        if (\count($shipped) !== \count(self::RULE_IDS)) {
            throw new \LogicException('The shipped workflow template "lifecycle" lacks a comment rule.');
        }

        return $shipped;
    }
}
