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

    /** A program name as a project may set it: a word of letters, digits and ".", "_", "+" and "-". */
    public const string PROGRAM_PATTERN = '/^[A-Za-z0-9._+-]{1,40}$/D';

    public const int MAX_PROJECT_PROGRAMS = 50;

    /**
     * The list of the project when it sets one, else the instance flag. An invalid entry in the project list is dropped.
     *
     * @return list<string>
     */
    public function subcommandPrograms(Project $project): array
    {
        $own = array_slice(array_values(array_filter(
            $this->projectSettings->subcommandPrograms($project) ?? [],
            static fn (string $program): bool => 1 === preg_match(self::PROGRAM_PATTERN, $program),
        )), 0, self::MAX_PROJECT_PROGRAMS);
        if ([] !== $own) {
            return $own;
        }

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
