<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class BoardAutomationSettingsTest extends TestCase
{
    public function test_a_new_settings_row_opens_no_epic_pull_request(): void
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');
        $settings = new BoardAutomationSettings(new Project($owner, 'epics'));

        self::assertFalse($settings->openEpicPullRequests);
    }
}
