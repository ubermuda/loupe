<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Project\Entity\Project;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** What the bridge collects from the tool calls of the worker runs of a project. */
final readonly class ToolCallCollectionSettings
{
    /** The programs whose second word joins the signature of a shell command, as a comma list. */
    public const string SUBCOMMAND_PROGRAMS_FLAG = 'insights.subcommand_programs';

    public const string DEFAULT_SUBCOMMAND_PROGRAMS = 'git,just,npm,pnpm,yarn,cargo,go,docker,gh,composer,make,pip,uv';

    public function __construct(
        private FeatureFlagService $featureFlags,
        private ProjectCollectionSettingsInterface $projectSettings,
    ) {
    }

    /**
     * The flag is an instance setting, so every project reads the same list.
     *
     * @return list<string>
     */
    public function subcommandPrograms(Project $project): array
    {
        $programs = self::parse($this->featureFlags->getStringValue(self::SUBCOMMAND_PROGRAMS_FLAG, self::DEFAULT_SUBCOMMAND_PROGRAMS));

        return [] === $programs ? self::parse(self::DEFAULT_SUBCOMMAND_PROGRAMS) : $programs;
    }

    public function collectFullText(Project $project): bool
    {
        return $this->projectSettings->collectFullText($project);
    }

    /** @return list<string> */
    private static function parse(string $list): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $list)),
            static fn (string $program): bool => '' !== $program,
        ));
    }
}
