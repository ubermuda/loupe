<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Service\ProjectCollectionSettingsInterface;
use App\Module\Bridge\Service\ToolCallCollectionSettings;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class ToolCallCollectionSettingsTest extends TestCase
{
    private const array DEFAULT = ['git', 'just', 'npm', 'pnpm', 'yarn', 'cargo', 'go', 'docker', 'gh', 'composer', 'make', 'pip', 'uv'];

    /** @return iterable<string, array{string, list<string>}> */
    public static function flagValues(): iterable
    {
        yield 'a plain list' => ['git,just', ['git', 'just']];
        yield 'spaces and empty entries' => [' git , ,just,, ', ['git', 'just']];
        yield 'an empty value' => ['', self::DEFAULT];
        yield 'commas alone' => [' , ,', self::DEFAULT];
    }

    /** @param list<string> $expected */
    #[DataProvider('flagValues')]
    public function test_it_reads_the_programs_from_the_flag(string $value, array $expected): void
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn($value);

        self::assertSame($expected, new ToolCallCollectionSettings($flags, $this->createStub(ProjectCollectionSettingsInterface::class))->subcommandPrograms($this->project()));
    }

    public function test_the_default_list_is_the_fallback_of_the_flag(): void
    {
        $flags = $this->createMock(FeatureFlagService::class);
        $flags->expects($this->once())
            ->method('getStringValue')
            ->with(ToolCallCollectionSettings::SUBCOMMAND_PROGRAMS_FLAG, implode(',', self::DEFAULT))
            ->willReturnArgument(1);

        self::assertSame(self::DEFAULT, new ToolCallCollectionSettings($flags, $this->createStub(ProjectCollectionSettingsInterface::class))->subcommandPrograms($this->project()));
    }

    public function test_the_programs_of_the_project_replace_the_instance_list(): void
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn('git,just');
        $project = $this->project();
        $projectSettings = $this->createStub(ProjectCollectionSettingsInterface::class);
        $projectSettings->method('subcommandPrograms')->willReturn(['bazel', 'c++']);

        self::assertSame(['bazel', 'c++'], new ToolCallCollectionSettings($flags, $projectSettings)->subcommandPrograms($project));
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function projectLists(): iterable
    {
        yield 'an empty list' => [[], ['git', 'just']];
        yield 'only invalid names' => [['two words', '', str_repeat('a', 41), 'a/b'], ['git', 'just']];
        yield 'a valid name among invalid ones' => [['two words', 'bazel'], ['bazel']];
    }

    /**
     * @param list<string> $own
     * @param list<string> $expected
     */
    #[DataProvider('projectLists')]
    public function test_an_unusable_project_list_falls_back_to_the_instance_list(array $own, array $expected): void
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturn('git,just');
        $projectSettings = $this->createStub(ProjectCollectionSettingsInterface::class);
        $projectSettings->method('subcommandPrograms')->willReturn($own);

        self::assertSame($expected, new ToolCallCollectionSettings($flags, $projectSettings)->subcommandPrograms($this->project()));
    }

    public function test_a_project_list_is_cut_at_the_limit(): void
    {
        $programs = array_map(static fn (int $index): string => 'tool'.$index, range(1, ToolCallCollectionSettings::MAX_PROJECT_PROGRAMS + 5));
        $projectSettings = $this->createStub(ProjectCollectionSettingsInterface::class);
        $projectSettings->method('subcommandPrograms')->willReturn($programs);

        $result = new ToolCallCollectionSettings($this->createStub(FeatureFlagService::class), $projectSettings)->subcommandPrograms($this->project());

        self::assertSame(array_slice($programs, 0, ToolCallCollectionSettings::MAX_PROJECT_PROGRAMS), $result);
    }

    #[DataProvider('projectAnswers')]
    public function test_the_project_settings_decide_whether_the_full_text_is_collected(bool $answer): void
    {
        $project = $this->project();
        $projectSettings = $this->createMock(ProjectCollectionSettingsInterface::class);
        $projectSettings->expects($this->once())->method('collectFullText')->with($project)->willReturn($answer);

        self::assertSame($answer, new ToolCallCollectionSettings($this->createStub(FeatureFlagService::class), $projectSettings)->collectFullText($project));
    }

    /** @return iterable<string, array{bool}> */
    public static function projectAnswers(): iterable
    {
        yield 'on' => [true];
        yield 'off' => [false];
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'settings@example.com', password: 'x'), 'Settings');
    }
}
