<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardAutomationSettingsTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function patterns(): iterable
    {
        yield 'the default pattern' => [BoardAutomationSettings::DEFAULT_EPIC_BRANCH_PATTERN, 'epic/42'];
        yield 'a custom pattern' => ['feature/epic-{number}-base', 'feature/epic-42-base'];
        yield 'no pattern' => [null, null];
        yield 'an empty pattern' => ['', null];
    }

    #[DataProvider('patterns')]
    public function test_the_epic_branch_puts_the_card_number_into_the_pattern(?string $pattern, ?string $branch): void
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');
        $settings = new BoardAutomationSettings(new Project($owner, 'epics'), epicBranchPattern: $pattern);

        self::assertSame($branch, $settings->epicBranchOf(42));
    }

    public function test_a_new_settings_row_has_the_default_pattern_and_opens_no_epic_pull_request(): void
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');
        $settings = new BoardAutomationSettings(new Project($owner, 'epics'));

        self::assertSame('epic/{number}', $settings->epicBranchPattern);
        self::assertFalse($settings->openEpicPullRequests);
    }
}
