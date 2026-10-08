<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service\Dev;

use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\BucketTimeComputer;
use App\Module\Inbox\Service\Dev\DevCodexRunSeeder;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DevCodexRunSeederTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use BridgeScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_writes_two_codex_runs_whose_facts_hold_the_harness_and_the_calls_once(): void
    {
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'dev-codex-'.uniqid().'@example.com'), 'Codex');
        $this->seedColumns($project);
        $em->flush();

        self::assertTrue($this->seeder()->seed($project));
        self::assertFalse($this->seeder()->seed($project));

        $em->clear();
        $facts = $this->service(WorkerRunFactRepository::class)->findBy(['project' => $project->id]);
        self::assertCount(2, $facts);
        foreach ($facts as $fact) {
            self::assertInstanceOf(WorkerRunFact::class, $fact);
            self::assertSame('codex', $fact->harness);
            self::assertSame('chatgpt', $fact->account);
            self::assertSame('gpt-6-sol', $fact->model);
            self::assertGreaterThan(0, $fact->costMicroUsd);
            self::assertGreaterThan(0, $fact->toolCalls);
            self::assertGreaterThan(0, $fact->subagentMs);
            self::assertNotNull($fact->modelTimeMs);
            self::assertNotNull($fact->peakContextTokens);
        }
        self::assertNotEquals($facts[0]->endedAt?->format('Y-m-d'), $facts[1]->endedAt?->format('Y-m-d'));
    }

    private function seeder(): DevCodexRunSeeder
    {
        return new DevCodexRunSeeder(
            $this->em(),
            $this->service(BoardColumnRepository::class),
            $this->service(CardRepository::class),
            $this->service(CardEventRepository::class),
            $this->service(WorkerRunRepository::class),
            $this->service(BucketTimeComputer::class),
        );
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
