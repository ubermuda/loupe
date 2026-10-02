<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboxProjectSettingsTest extends TestCase
{
    public function test_every_switch_is_on_by_default(): void
    {
        $settings = new InboxProjectSettings($this->project());

        foreach (InboxCardWaitTrigger::cases() as $trigger) {
            self::assertTrue($settings->isOn($trigger), $trigger->value);
        }
    }

    /** @return iterable<string, array{InboxCardWaitTrigger, string}> */
    public static function switches(): iterable
    {
        yield 'document in review' => [InboxCardWaitTrigger::DocumentInReview, 'documentInReview'];
        yield 'run blocked' => [InboxCardWaitTrigger::RunBlocked, 'runBlocked'];
        yield 'run gave up' => [InboxCardWaitTrigger::RunGaveUp, 'runGaveUp'];
        yield 'run waiting for person' => [InboxCardWaitTrigger::RunWaitingForPerson, 'runWaitingForPerson'];
        yield 'pull request ready' => [InboxCardWaitTrigger::PullRequestReady, 'pullRequestReady'];
        yield 'pull request fix stopped' => [InboxCardWaitTrigger::PullRequestFixStopped, 'pullRequestFixStopped'];
        yield 'card paused' => [InboxCardWaitTrigger::CardPaused, 'cardPaused'];
    }

    #[DataProvider('switches')]
    public function test_each_trigger_reads_its_own_switch(InboxCardWaitTrigger $trigger, string $property): void
    {
        $settings = new InboxProjectSettings($this->project());
        $settings->{$property} = false;

        foreach (InboxCardWaitTrigger::cases() as $other) {
            self::assertSame($other !== $trigger, $settings->isOn($other), $other->value);
        }
    }

    private function project(): Project
    {
        return new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Loupe');
    }
}
