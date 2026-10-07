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
