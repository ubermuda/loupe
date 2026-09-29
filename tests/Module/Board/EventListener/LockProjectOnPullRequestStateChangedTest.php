<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\EventListener\LockProjectOnPullRequestStateChanged;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class LockProjectOnPullRequestStateChangedTest extends TestCase
{
    #[DataProvider('reads')]
    public function test_it_locks_the_project_whenever_the_board_is_on(PullRequestSnapshot $previous, PullRequestSnapshot $current, bool $boardEnabled, bool $locks): void
    {
        $project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'x'), 'lock-project');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($locks ? $this->once() : $this->never())->method('lock')->with($project, LockMode::PESSIMISTIC_WRITE);
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($boardEnabled);

        new LockProjectOnPullRequestStateChanged($em, new BoardAvailability($flags))(
            new PullRequestStateChanged(new ForgePullRequest($project, 'github', 'acme/widgets', 5), $previous, $current),
        );
    }

    /** @return iterable<string, array{PullRequestSnapshot, PullRequestSnapshot, bool, bool}> */
    public static function reads(): iterable
    {
        $passed = new PullRequestSnapshot(checks: PullRequestChecks::Passed, checksSha: 'abc1234');
        $merged = new PullRequestSnapshot(state: PullRequestState::Merged);

        yield 'green checks' => [new PullRequestSnapshot(), $passed, true, true];
        yield 'a merge' => [new PullRequestSnapshot(), $merged, true, true];
        yield 'a draft marked ready with green checks' => [new PullRequestSnapshot(draft: true, checks: PullRequestChecks::Passed, checksSha: 'abc1234'), $passed, true, true];
        yield 'failed checks' => [new PullRequestSnapshot(), new PullRequestSnapshot(checks: PullRequestChecks::Failed, checksSha: 'abc1234'), true, true];
        yield 'green checks on a draft' => [new PullRequestSnapshot(), new PullRequestSnapshot(draft: true, checks: PullRequestChecks::Passed, checksSha: 'abc1234'), true, true];
        yield 'a verdict alone' => [$passed, $passed, true, true];
        yield 'green checks while the board is off' => [new PullRequestSnapshot(), $passed, false, false];
        yield 'a merge while the board is off' => [new PullRequestSnapshot(), $merged, false, false];
    }
}
