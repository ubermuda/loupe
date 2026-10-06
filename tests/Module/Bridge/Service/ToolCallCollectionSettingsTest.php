<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
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

        self::assertSame($expected, new ToolCallCollectionSettings($flags)->subcommandPrograms($this->project()));
    }

    public function test_the_default_list_is_the_fallback_of_the_flag(): void
    {
        $flags = $this->createMock(FeatureFlagService::class);
        $flags->expects($this->once())
            ->method('getStringValue')
            ->with(ToolCallCollectionSettings::SUBCOMMAND_PROGRAMS_FLAG, implode(',', self::DEFAULT))
            ->willReturnArgument(1);

        self::assertSame(self::DEFAULT, new ToolCallCollectionSettings($flags)->subcommandPrograms($this->project()));
    }

    public function test_no_project_collects_the_full_text(): void
    {
        self::assertFalse(new ToolCallCollectionSettings($this->createStub(FeatureFlagService::class))->collectFullText($this->project()));
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'settings@example.com', password: 'x'), 'Settings');
    }
}
