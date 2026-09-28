<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_find_latest_session_of_card_answers_the_newest_run_with_a_session(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'latest-session-'.uniqid().'@example.com'), 'Latest session');
        $other = $this->project($em, $this->user($em, 'latest-session-other-'.uniqid().'@example.com'), 'Other');
        $cardId = Uuid::v7();

        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-01 10:00:00'), cardId: $cardId);
        $newest = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-02 10:00:00'), cardId: $cardId);
        $noSession = $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-03 10:00:00'), cardId: $cardId);
        $noSession->sessionId = null;
        $this->seedRun($em, $project, new \DateTimeImmutable('2026-09-04 10:00:00'));
        $this->seedRun($em, $other, new \DateTimeImmutable('2026-09-05 10:00:00'), cardId: $cardId);
        $em->flush();

        $runs = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);

        self::assertSame($newest, $runs->findLatestSessionOfCard($project, $cardId));
        self::assertNull($runs->findLatestSessionOfCard($project, Uuid::v7()));
    }
}
