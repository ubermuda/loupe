<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Module\Insights\Service\AnalysisSettings;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class AnalysisSettingsTest extends TestCase
{
    public function test_the_project_value_wins_over_the_flag(): void
    {
        $settings = new InsightsProjectSettings($this->project());
        $settings->defaultModel = 'opus';
        $settings->defaultEffort = 'high';

        $resolver = $this->resolver($settings, ['insights.default_analysis_model' => 'haiku', 'insights.default_analysis_effort' => 'low']);

        self::assertSame('opus', $resolver->modelFor($this->project()));
        self::assertSame('high', $resolver->effortFor($this->project()));
    }

    public function test_a_project_without_a_value_reads_the_flag(): void
    {
        $resolver = $this->resolver(new InsightsProjectSettings($this->project()), ['insights.default_analysis_model' => 'haiku', 'insights.default_analysis_effort' => 'low']);

        self::assertSame('haiku', $resolver->modelFor($this->project()));
        self::assertSame('low', $resolver->effortFor($this->project()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableFlags(): iterable
    {
        yield 'empty values' => ['', ''];
        yield 'malformed values' => ['two words', 'extreme'];
    }

    #[DataProvider('unusableFlags')]
    public function test_an_unusable_flag_falls_back_to_the_coded_default(string $model, string $effort): void
    {
        $resolver = $this->resolver(null, ['insights.default_analysis_model' => $model, 'insights.default_analysis_effort' => $effort]);

        self::assertSame(AnalysisSettings::DEFAULT_MODEL, $resolver->modelFor($this->project()));
        self::assertSame(AnalysisSettings::DEFAULT_EFFORT, $resolver->effortFor($this->project()));
        self::assertSame('sonnet', AnalysisSettings::DEFAULT_MODEL);
        self::assertSame('medium', AnalysisSettings::DEFAULT_EFFORT);
    }

    public function test_the_coded_defaults_are_the_fallbacks_of_the_flags(): void
    {
        $flags = $this->createMock(FeatureFlagService::class);
        $flags->expects($this->exactly(2))
            ->method('getStringValue')
            ->willReturnCallback(static fn (string $name, string $default): string => match ($name) {
                AnalysisSettings::MODEL_FLAG => 'sonnet' === $default ? $default : 'wrong',
                AnalysisSettings::EFFORT_FLAG => 'medium' === $default ? $default : 'wrong',
                default => 'wrong',
            });
        $resolver = new AnalysisSettings($this->repository(null), $flags);

        self::assertSame('sonnet', $resolver->modelFor($this->project()));
        self::assertSame('medium', $resolver->effortFor($this->project()));
    }

    public function test_full_text_is_off_unless_the_project_turns_it_on(): void
    {
        $on = new InsightsProjectSettings($this->project());
        $on->collectFullText = true;

        self::assertFalse($this->resolver(null, [])->collectFullText($this->project()));
        self::assertFalse($this->resolver(new InsightsProjectSettings($this->project()), [])->collectFullText($this->project()));
        self::assertTrue($this->resolver($on, [])->collectFullText($this->project()));
    }

    /** @param array<string, string> $flagValues */
    private function resolver(?InsightsProjectSettings $settings, array $flagValues): AnalysisSettings
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willReturnCallback(static fn (string $name, string $default): string => $flagValues[$name] ?? $default);

        return new AnalysisSettings($this->repository($settings), $flags);
    }

    private function repository(?InsightsProjectSettings $settings): InsightsProjectSettingsRepository
    {
        $repository = $this->createStub(InsightsProjectSettingsRepository::class);
        $repository->method('findForProject')->willReturn($settings);

        return $repository;
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'analysis-settings@example.com', password: 'x'), 'Settings');
    }
}
