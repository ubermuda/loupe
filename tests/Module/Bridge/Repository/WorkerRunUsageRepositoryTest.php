<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Repository\WorkerRunUsageRepository;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunUsageRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_card_total_skips_the_usage_of_another_subject_with_the_same_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'usage-subject@example.com'), 'Usage Subject');
        $sharedId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $sharedId), inputTokens: 100);
        $em->persist(new WorkerRunUsage(null, $project, 'analysis', $sharedId, 'analysis', 'claude-haiku', WorkerRunUsageSource::Reported, 5000, 0, 0, 0, '1.000000'));
        $em->flush();

        $sum = $this->repository()->sumForCard($project, $sharedId);

        self::assertSame(1, $sum['rows']);
        self::assertSame(100, $sum['input']);
        self::assertSame('0.012345', $sum['cost']);
    }

    private function repository(): WorkerRunUsageRepository
    {
        $repository = self::getContainer()->get(WorkerRunUsageRepository::class);
        self::assertInstanceOf(WorkerRunUsageRepository::class, $repository);

        return $repository;
    }
}
