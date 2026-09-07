<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Mcp\FlagGatedToolInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The MCP tools this instance advertises, for the screens that list them.
 *
 * Both the roster and the gates are read from the server itself — the registry
 * the #[McpTool] attributes build, and each tool's own requiredFlag() — so a
 * tool added, renamed or gated cannot be described differently here than it
 * behaves there.
 */
final class AdvertisedTools
{
    private const string KEY_PREFIX = 'project.connect.tool.';

    /**
     * Reading order, and nothing else: writing a document comes before reading
     * its review, which comes before answering it. A tool missing from this
     * list is still advertised — it lands at the end.
     */
    private const array ORDER = [
        'document_create',
        'document_list',
        'document_get',
        'document_revise',
        'document_rename',
        'document_archive',
        'document_unarchive',
        'document_set_tags',
        'document_set_series',
        'document_set_references',
        'document_highlight',
        'document_get_review',
        'document_reply_to_comment',
        'document_mark_comment_addressed',
        'tag_list',
        'series_list',
        'series_rename',
        'site_review_get',
        'site_review_mark_comment_addressed',
        'card_create',
        'card_list',
        'card_get',
        'card_update',
    ];

    /** @var list<array{name: string, descriptionKey: string}>|null */
    private ?array $enabled = null;

    /** @param iterable<FlagGatedToolInterface> $gatedTools */
    public function __construct(
        private readonly FeatureFlagService $featureFlags,

        #[AutowireIterator('app.mcp_gated_tool')]
        private readonly iterable $gatedTools,

        #[Autowire(service: 'mcp.server.builder')]
        private readonly Builder $builder,

        #[Autowire(service: 'mcp.registry')]
        private readonly RegistryInterface $registry,
    ) {
    }

    /**
     * What an agent connecting right now would be offered: the same list
     * FlagGatedListToolsHandler answers tools/list with.
     *
     * @return list<array{name: string, descriptionKey: string}>
     */
    public function enabled(): array
    {
        if (null !== $this->enabled) {
            return $this->enabled;
        }

        // The loaders populate the registry when the server is built, so an
        // unbuilt one answers with nothing at all rather than failing.
        $this->builder->build();

        $gates = [];
        foreach ($this->gatedTools as $gatedTool) {
            $gates[$gatedTool->gatedToolName()] = $gatedTool->requiredFlag();
        }

        $names = [];
        foreach ($this->registry->getTools()->references as $tool) {
            \assert($tool instanceof Tool);

            if (isset($gates[$tool->name]) && !$this->featureFlags->isEnabled($gates[$tool->name])) {
                continue;
            }

            $names[] = $tool->name;
        }

        usort($names, fn (string $left, string $right): int => $this->rank($left) <=> $this->rank($right));

        return $this->enabled = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'descriptionKey' => self::KEY_PREFIX.$name,
            ],
            $names,
        );
    }

    private function rank(string $name): int
    {
        $position = array_search($name, self::ORDER, true);

        return false === $position ? \count(self::ORDER) : $position;
    }
}
