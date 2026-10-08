<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Mcp\WorkerRunToolCallsTool;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;
use App\Module\Bridge\ValueObject\WorkerRunToolCallReport;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunToolCallsToolTest extends KernelTestCase
{
    use BridgeScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_reads_the_calls_of_a_run_by_seq_a_page_at_a_time(): void
    {
        [$project] = $this->projects('tool-calls-page');
        $run = $this->seedRun($this->em(), $project);
        foreach ([3, 1, 2] as $seq) {
            $this->seedToolCall($run, $seq, 'Tool'.$seq);
        }
        $this->actAsMcpTokenBoundTo($project);

        $first = $this->tool()((string) $run->id, perPage: 2);
        $second = $this->tool()((string) $run->id, page: 2, perPage: 2);

        self::assertSame([1, 2], array_column($first['calls'], 'seq'));
        self::assertSame(['page' => 1, 'perPage' => 2, 'total' => 3, 'hasMore' => true], array_diff_key($first, ['calls' => true]));
        self::assertSame([3], array_column($second['calls'], 'seq'));
        self::assertFalse($second['hasMore']);
        self::assertSame([
            'seq' => 1,
            'tool' => 'Tool1',
            'startedAt' => '2026-01-01T10:00:01.000+00:00',
            'durationMs' => 1500,
            'isError' => false,
            'inSubagent' => false,
            'backgroundId' => null,
            'waitsOn' => null,
            'signatures' => ['Tool1'],
            'fullText' => null,
        ], $first['calls'][0]);
    }

    public function test_it_reads_every_field_the_bridge_sent(): void
    {
        [$project] = $this->projects('tool-calls-fields');
        $run = $this->seedRun($this->em(), $project);
        $this->repository()->insertNew($run, [new WorkerRunToolCallReport(
            seq: 1,
            tool: 'Bash',
            startedAt: new \DateTimeImmutable('2026-01-01 10:00:02.345'),
            durationMs: null,
            isError: null,
            inSubagent: true,
            backgroundId: 'bg-7',
            waitsOn: 'bg-6',
            signatures: ['git status', 'just phpunit'],
            fullText: 'git status && just phpunit',
        )]);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame([
            'seq' => 1,
            'tool' => 'Bash',
            'startedAt' => '2026-01-01T10:00:02.345+00:00',
            'durationMs' => null,
            'isError' => null,
            'inSubagent' => true,
            'backgroundId' => 'bg-7',
            'waitsOn' => 'bg-6',
            'signatures' => ['git status', 'just phpunit'],
            'fullText' => 'git status && just phpunit',
        ], $this->tool()((string) $run->id)['calls'][0]);
    }

    public function test_an_out_of_range_page_size_and_page_are_clamped(): void
    {
        [$project] = $this->projects('tool-calls-clamp');
        $run = $this->seedRun($this->em(), $project);
        $this->seedToolCall($run);
        $this->actAsMcpTokenBoundTo($project);

        $large = $this->tool()((string) $run->id, page: 0, perPage: 500);
        $small = $this->tool()((string) $run->id, page: -3, perPage: 0);

        self::assertSame(['page' => 1, 'perPage' => 100, 'total' => 1, 'hasMore' => false], array_diff_key($large, ['calls' => true]));
        self::assertSame(['page' => 1, 'perPage' => 1, 'total' => 1, 'hasMore' => false], array_diff_key($small, ['calls' => true]));
        self::assertCount(1, $large['calls']);
    }

    public function test_a_run_with_no_calls_reads_an_empty_page(): void
    {
        [$project] = $this->projects('tool-calls-empty');
        $run = $this->seedRun($this->em(), $project);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['calls' => [], 'page' => 1, 'perPage' => 20, 'total' => 0, 'hasMore' => false], $this->tool()((string) $run->id));
    }

    public function test_a_run_of_another_project_is_an_unknown_run(): void
    {
        [$project, $other] = $this->projects('tool-calls-scope');
        $foreign = $this->seedRun($this->em(), $other);
        $this->seedToolCall($foreign);
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(\sprintf('Worker run "%s" not found or not accessible.', $foreign->id), $this->refusal((string) $foreign->id));
    }

    public function test_an_unbound_token_is_refused(): void
    {
        [$project] = $this->projects('tool-calls-unbound');
        $run = $this->seedRun($this->em(), $project);
        $this->seedToolCall($run);
        $this->actAsUnboundMcpToken($project->owner);

        self::assertStringNotContainsString('not found', $this->refusal((string) $run->id));
    }

    /** @return array{Project, Project} two projects of one owner */
    private function projects(string $name): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'mcp-'.$name.'-'.uniqid().'@example.com');

        return [$this->project($em, $owner, 'Bound '.$name), $this->project($em, $owner, 'Other '.$name)];
    }

    private function refusal(string $runId): string
    {
        try {
            $this->tool()($runId);
        } catch (ToolCallException $e) {
            return $e->getMessage();
        }

        self::fail('Expected a refusal.');
    }

    private function tool(): WorkerRunToolCallsTool
    {
        $tool = self::getContainer()->get(WorkerRunToolCallsTool::class);
        self::assertInstanceOf(WorkerRunToolCallsTool::class, $tool);

        return $tool;
    }

    private function repository(): WorkerRunToolCallRepository
    {
        $repository = self::getContainer()->get(WorkerRunToolCallRepository::class);
        self::assertInstanceOf(WorkerRunToolCallRepository::class, $repository);

        return $repository;
    }
}
