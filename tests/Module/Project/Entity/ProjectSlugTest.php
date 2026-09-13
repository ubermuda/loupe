<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class ProjectSlugTest extends TestCase
{
    public function test_a_new_project_takes_the_slug_of_its_name(): void
    {
        $project = new Project($this->owner(), 'My App');

        self::assertSame('my-app', $project->slug);
    }

    public function test_a_rename_changes_the_slug(): void
    {
        $project = new Project($this->owner(), 'My App');

        $project->name = 'Café Board';

        self::assertSame('cafe-board', $project->slug);
    }

    public function test_writing_the_same_name_again_keeps_a_stored_slug(): void
    {
        $project = new Project($this->owner(), 'My App');
        // The migration gives a colliding project a suffixed slug, and Doctrine
        // hydrates it raw, past the hook.
        new \ReflectionProperty(Project::class, 'slug')->setRawValue($project, 'my-app-2');

        $project->name = 'My App';

        self::assertSame('my-app-2', $project->slug);
    }

    private function owner(): User
    {
        return new User(fullName: 'U', email: 'slug-owner@example.com', password: 'x');
    }
}
