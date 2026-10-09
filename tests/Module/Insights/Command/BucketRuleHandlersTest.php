<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Command\CreateBucketRuleCommand;
use App\Module\Insights\Command\CreateBucketRuleHandler;
use App\Module\Insights\Command\DeleteBucketRuleCommand;
use App\Module\Insights\Command\DeleteBucketRuleHandler;
use App\Module\Insights\Command\MoveBucketRuleCommand;
use App\Module\Insights\Command\MoveBucketRuleHandler;
use App\Module\Insights\Entity\BucketRuleDirection;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Service\BucketRuleWriter;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class BucketRuleHandlersTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->transport()->reset();
    }

    public function test_a_created_rule_goes_last_and_asks_for_a_recompute(): void
    {
        $project = $this->scenarioProject('rule-create');
        $first = $this->create($project, 'Bash:git *', 'git');
        $second = $this->create($project, '  Bash:just *  ', 'just');

        self::assertSame([0, 1], [$first->position, $second->position]);
        self::assertSame(['Bash:git *', 'Bash:just *'], $this->patterns($project));
        self::assertEquals(
            array_fill(0, 2, new RecomputeBucketTimes((string) $project->id)),
            $this->dispatched(),
        );
    }

    public function test_the_position_follows_the_last_rule_after_a_delete(): void
    {
        $project = $this->scenarioProject('rule-create-after-delete');
        $this->create($project, 'a*', 'a');
        $middle = $this->create($project, 'b*', 'b');
        $this->create($project, 'c*', 'c');
        $this->service(DeleteBucketRuleHandler::class)(new DeleteBucketRuleCommand($middle));

        $fourth = $this->create($project, 'd*', 'd');

        self::assertSame(3, $fourth->position);
        self::assertSame(['a*', 'c*', 'd*'], $this->patterns($project));
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function invalidRules(): iterable
    {
        yield 'a blank pattern' => ['   ', 'git', ['pattern' => BucketRuleWriter::PATTERN_BLANK]];
        yield 'a pattern of 121 characters' => [str_repeat('a', 121), 'git', ['pattern' => BucketRuleWriter::PATTERN_TOO_LONG]];
        yield 'a bucket with a capital' => ['Bash:git *', 'Git', ['bucket' => BucketRuleWriter::BUCKET_INVALID]];
        yield 'an empty bucket' => ['Bash:git *', '', ['bucket' => BucketRuleWriter::BUCKET_INVALID]];
        yield 'a bucket of 65 characters' => ['Bash:git *', str_repeat('a', 65), ['bucket' => BucketRuleWriter::BUCKET_INVALID]];
        yield 'both' => ['', 'a b', ['pattern' => BucketRuleWriter::PATTERN_BLANK, 'bucket' => BucketRuleWriter::BUCKET_INVALID]];
    }

    /** @param array<string, string> $errors */
    #[DataProvider('invalidRules')]
    public function test_an_invalid_rule_is_refused_and_nothing_is_stored(string $pattern, string $bucket, array $errors): void
    {
        $project = $this->scenarioProject('rule-create-invalid-'.uniqid());

        try {
            $this->create($project, $pattern, $bucket);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }

        self::assertSame([], $this->patterns($project));
        self::assertSame([], $this->dispatched());
    }

    public function test_a_pattern_of_120_characters_and_a_bucket_of_64_are_accepted(): void
    {
        $project = $this->scenarioProject('rule-create-edge');

        $rule = $this->create($project, str_repeat('a', 120), str_repeat('b', 64));

        self::assertSame(120, mb_strlen($rule->pattern));
    }

    public function test_a_project_holds_at_most_fifty_rules(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('rule-create-limit');
        for ($position = 0; $position < InsightsBucketRule::MAX_PER_PROJECT; ++$position) {
            $em->persist(new InsightsBucketRule($project, 'Bash:tool'.$position, 'tool', $position));
        }
        $em->flush();
        $other = $this->scenarioProject('rule-create-limit-other');
        $this->transport()->reset();

        try {
            $this->create($project, 'Bash:git *', 'git');
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['pattern' => BucketRuleWriter::LIMIT_REACHED], $e->errors);
        }

        self::assertTrue($em->isOpen());
        self::assertSame([], $this->dispatched());
        self::assertSame(0, $this->create($other, 'Bash:git *', 'git')->position);
        self::assertCount(InsightsBucketRule::MAX_PER_PROJECT, $this->patterns($project));
    }

    public function test_a_delete_removes_the_rule_and_asks_for_a_recompute(): void
    {
        $project = $this->scenarioProject('rule-delete');
        $this->create($project, 'a*', 'a');
        $doomed = $this->create($project, 'b*', 'b');
        $this->transport()->reset();

        $this->service(DeleteBucketRuleHandler::class)(new DeleteBucketRuleCommand($doomed));

        self::assertSame(['a*'], $this->patterns($project));
        self::assertEquals([new RecomputeBucketTimes((string) $project->id)], $this->dispatched());
    }

    public function test_deleting_a_rule_that_is_already_gone_changes_nothing(): void
    {
        $project = $this->scenarioProject('rule-delete-twice');
        $rule = $this->create($project, 'a*', 'a');
        $this->em()->getConnection()->executeStatement('DELETE FROM insights_bucket_rules WHERE id = ?', [(string) $rule->id]);
        $this->transport()->reset();

        $this->service(DeleteBucketRuleHandler::class)(new DeleteBucketRuleCommand($rule));

        self::assertTrue($this->em()->isOpen());
        self::assertSame([], $this->dispatched());
    }

    /** @return iterable<string, array{int, BucketRuleDirection, list<string>}> */
    public static function moves(): iterable
    {
        yield 'the middle rule up' => [1, BucketRuleDirection::Up, ['b*', 'a*', 'c*']];
        yield 'the middle rule down' => [1, BucketRuleDirection::Down, ['a*', 'c*', 'b*']];
        yield 'the last rule up' => [2, BucketRuleDirection::Up, ['a*', 'c*', 'b*']];
        yield 'the first rule down' => [0, BucketRuleDirection::Down, ['b*', 'a*', 'c*']];
    }

    /** @param list<string> $expected */
    #[DataProvider('moves')]
    public function test_a_move_swaps_the_rule_with_its_neighbour(int $index, BucketRuleDirection $direction, array $expected): void
    {
        $project = $this->scenarioProject('rule-move-'.uniqid());
        $rules = [$this->create($project, 'a*', 'a'), $this->create($project, 'b*', 'b'), $this->create($project, 'c*', 'c')];
        $this->transport()->reset();

        $this->service(MoveBucketRuleHandler::class)(new MoveBucketRuleCommand($rules[$index], $direction));

        self::assertSame($expected, $this->patterns($project));
        self::assertEquals([new RecomputeBucketTimes((string) $project->id)], $this->dispatched());
    }

    /** @return iterable<string, array{int, BucketRuleDirection}> */
    public static function edgeMoves(): iterable
    {
        yield 'the first rule up' => [0, BucketRuleDirection::Up];
        yield 'the last rule down' => [2, BucketRuleDirection::Down];
    }

    #[DataProvider('edgeMoves')]
    public function test_a_move_past_the_end_changes_nothing(int $index, BucketRuleDirection $direction): void
    {
        $project = $this->scenarioProject('rule-move-edge-'.uniqid());
        $rules = [$this->create($project, 'a*', 'a'), $this->create($project, 'b*', 'b'), $this->create($project, 'c*', 'c')];
        $this->transport()->reset();

        $this->service(MoveBucketRuleHandler::class)(new MoveBucketRuleCommand($rules[$index], $direction));

        self::assertSame(['a*', 'b*', 'c*'], $this->patterns($project));
        self::assertSame([], $this->dispatched());
    }

    public function test_a_move_reads_the_stored_order_not_a_stale_entity(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('rule-move-stale');
        $first = $this->create($project, 'a*', 'a');
        $this->create($project, 'b*', 'b');
        $em->getConnection()->executeStatement('UPDATE insights_bucket_rules SET position = 9 WHERE id = ?', [(string) $first->id]);

        $this->service(MoveBucketRuleHandler::class)(new MoveBucketRuleCommand($first, BucketRuleDirection::Up));

        self::assertSame(['a*', 'b*'], $this->patterns($project));
    }

    private function create(Project $project, string $pattern, string $bucket): InsightsBucketRule
    {
        return $this->service(CreateBucketRuleHandler::class)(new CreateBucketRuleCommand($project, $pattern, $bucket));
    }

    /** @return list<string> the patterns in order of position, read fresh */
    private function patterns(Project $project): array
    {
        $this->em()->clear();
        $stored = $this->em()->find($project::class, $project->id) ?? throw new \LogicException('The project exists.');

        return array_map(static fn (InsightsBucketRule $rule): string => $rule->pattern, $this->service(InsightsBucketRuleRepository::class)->findOrdered($stored));
    }

    /** @return list<object> */
    private function dispatched(): array
    {
        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
