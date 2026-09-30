<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaInviteExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class BetaInviteExporterTest extends TestCase
{
    public function test_it_exports_when_the_user_became_a_beta_tester_and_not_the_note(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        [$invite] = BetaInvite::issue(null, 'reddit u/alice');
        $invite->redeem($user);
        $invite->redeemedAt = new \DateTimeImmutable('2026-09-30T12:00:00+00:00');

        $row = iterator_to_array(new BetaInviteExporter($this->repositoryReturning($invite))->export($user));

        self::assertSame(['betaTesterSince' => '2026-09-30T12:00:00+00:00'], $row);
    }

    public function test_it_exports_nothing_for_a_user_who_redeemed_no_invite(): void
    {
        $exporter = new BetaInviteExporter($this->repositoryReturning(null));

        self::assertSame([], iterator_to_array($exporter->export(new User('Bob B', 'bob@example.com', 'x'))));
        self::assertSame('beta_invite.json', $exporter->filename());
    }

    private function repositoryReturning(?BetaInvite $invite): BetaInviteRepository
    {
        /** @var BetaInviteRepository&Stub $repo */
        $repo = $this->createStub(BetaInviteRepository::class);
        $repo->method('findOneRedeemedBy')->willReturn($invite);

        return $repo;
    }
}
