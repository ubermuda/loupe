<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Command\UpdateAnalyticsSettingsCommand;
use App\Module\Insights\Command\UpdateAnalyticsSettingsHandler;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpdateAnalyticsSettingsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_first_save_stores_a_row_and_a_second_save_updates_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-save@example.com'), 'Analytics settings');

        $this->handler()(new UpdateAnalyticsSettingsCommand($project, 'opus', 'high', true));
        $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, 'low', false));

        $em->clear();
        $rows = $this->repository()->findBy(['project' => (string) $project->id]);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->defaultModel);
        self::assertSame('low', $rows[0]->defaultEffort);
        self::assertFalse($rows[0]->collectFullText);
    }

    public function test_a_partial_update_keeps_the_values_it_does_not_change(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-partial@example.com'), 'Analytics settings');

        $this->handler()(new UpdateAnalyticsSettingsCommand($project, 'opus', 'high', true));
        $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, 'low', false, changeModel: false, changeCollectFullText: false));

        $em->clear();
        $rows = $this->repository()->findBy(['project' => (string) $project->id]);
        self::assertCount(1, $rows);
        self::assertSame('opus', $rows[0]->defaultModel);
        self::assertSame('low', $rows[0]->defaultEffort);
        self::assertTrue($rows[0]->collectFullText);
    }

    public function test_the_subcommand_programs_are_stored_without_duplicates_and_null_clears_them(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-programs@example.com'), 'Analytics settings');

        $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, null, false, subcommandPrograms: ['git', 'bazel', 'git', 'c++']));
        $em->clear();
        self::assertSame(['git', 'bazel', 'c++'], $this->repository()->findOneBy(['project' => (string) $project->id])?->subcommandPrograms);

        $project = $em->find($project::class, $project->id) ?? throw new \LogicException();
        $this->handler()(new UpdateAnalyticsSettingsCommand($project, 'opus', null, false, changeEffort: false, changeCollectFullText: false, changeSubcommandPrograms: false));
        $em->clear();
        self::assertSame(['git', 'bazel', 'c++'], $this->repository()->findOneBy(['project' => (string) $project->id])?->subcommandPrograms);

        $project = $em->find($project::class, $project->id) ?? throw new \LogicException();
        $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, null, false, subcommandPrograms: []));
        $em->clear();
        self::assertNull($this->repository()->findOneBy(['project' => (string) $project->id])?->subcommandPrograms);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function malformedPrograms(): iterable
    {
        yield 'a name with a space' => [['git', 'two words'], UpdateAnalyticsSettingsHandler::INVALID_SUBCOMMAND_PROGRAMS];
        yield 'an empty name' => [[''], UpdateAnalyticsSettingsHandler::INVALID_SUBCOMMAND_PROGRAMS];
        yield 'a name of 41 characters' => [[str_repeat('a', 41)], UpdateAnalyticsSettingsHandler::INVALID_SUBCOMMAND_PROGRAMS];
        yield 'a path' => [['/usr/bin/git'], UpdateAnalyticsSettingsHandler::INVALID_SUBCOMMAND_PROGRAMS];
        yield 'fifty one names' => [array_map(static fn (int $index): string => 'tool'.$index, range(1, 51)), UpdateAnalyticsSettingsHandler::TOO_MANY_SUBCOMMAND_PROGRAMS];
    }

    /** @param list<string> $programs */
    #[DataProvider('malformedPrograms')]
    public function test_a_malformed_list_of_programs_is_refused_and_nothing_is_stored(array $programs, string $key): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-programs-refused-'.uniqid().'@example.com'), 'Analytics settings');

        try {
            $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, null, false, subcommandPrograms: $programs));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['subcommandPrograms' => $key], $e->errors);
        }

        self::assertNull($this->repository()->findForProject($project));
    }

    public function test_fifty_names_are_accepted(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-programs-fifty@example.com'), 'Analytics settings');

        $this->handler()(new UpdateAnalyticsSettingsCommand($project, null, null, false, subcommandPrograms: array_map(static fn (int $index): string => 'tool'.$index, range(1, 50))));

        self::assertCount(50, $this->repository()->findForProject($project)->subcommandPrograms ?? []);
    }

    /** @return iterable<string, array{?string, ?string, array<string, string>}> */
    public static function malformed(): iterable
    {
        yield 'a model with a space' => ['two words', null, ['model' => UpdateAnalyticsSettingsHandler::INVALID_MODEL]];
        yield 'an empty model' => ['', null, ['model' => UpdateAnalyticsSettingsHandler::INVALID_MODEL]];
        yield 'an unknown effort' => [null, 'extreme', ['effort' => UpdateAnalyticsSettingsHandler::INVALID_EFFORT]];
        yield 'both' => ['-flag', 'none', ['model' => UpdateAnalyticsSettingsHandler::INVALID_MODEL, 'effort' => UpdateAnalyticsSettingsHandler::INVALID_EFFORT]];
    }

    /** @param array<string, string> $errors */
    #[DataProvider('malformed')]
    public function test_a_malformed_value_is_refused_and_nothing_is_stored(?string $model, ?string $effort, array $errors): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'analytics-settings-refused-'.uniqid().'@example.com'), 'Analytics settings');

        try {
            $this->handler()(new UpdateAnalyticsSettingsCommand($project, $model, $effort, true));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertNull($this->repository()->findForProject($project));
    }

    private function handler(): UpdateAnalyticsSettingsHandler
    {
        $handler = self::getContainer()->get(UpdateAnalyticsSettingsHandler::class);
        self::assertInstanceOf(UpdateAnalyticsSettingsHandler::class, $handler);

        return $handler;
    }

    private function repository(): InsightsProjectSettingsRepository
    {
        $repository = self::getContainer()->get(InsightsProjectSettingsRepository::class);
        self::assertInstanceOf(InsightsProjectSettingsRepository::class, $repository);

        return $repository;
    }
}
