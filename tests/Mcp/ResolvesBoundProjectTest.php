<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectRefusal;
use App\Module\Project\Security\ProjectResolution;
use App\Tests\Support\McpRefusalMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ResolvesBoundProjectTest extends TestCase
{
    private const string BOARD_ID = '0192f3c4-5d6e-7f80-9123-000000000001';
    private const string SITE_ID = '0192f3c4-5d6e-7f80-9123-000000000002';

    public function test_several_projects_and_no_header_names_loupe_init_and_the_projects(): void
    {
        $message = self::message(ProjectResolution::refused(
            ProjectRefusal::SeveralProjectsAndNoHeader,
            [$this->project('Board', self::BOARD_ID), $this->project('Site', self::SITE_ID)],
        ));

        self::assertSame(
            'This login covers several projects, and this request names none. Run `loupe init` in the repository to choose one, then call the tool again. An HTTP client names the project in the X-Loupe-Project header instead. It covers: Board ('.self::BOARD_ID.'), Site ('.self::SITE_ID.').',
            $message,
        );
    }

    public function test_no_project_asks_for_a_project(): void
    {
        self::assertSame(
            'This login covers no project yet. Create a project in Loupe, then call the tool again.',
            self::message(ProjectResolution::refused(ProjectRefusal::NoProject)),
        );
    }

    public function test_header_not_covered_names_loupe_init_force_and_the_requested_value(): void
    {
        $message = self::message(ProjectResolution::refused(
            ProjectRefusal::HeaderNotCovered,
            [$this->project('Board', self::BOARD_ID)],
            self::SITE_ID,
        ));

        self::assertSame(
            'This login does not cover the project this request names ("'.self::SITE_ID.'"). Run `loupe init --force` in the repository to choose a project it covers. `loupe mcp` takes the project from `.loupe.yaml`, or from its `--project` option. An HTTP client sets the X-Loupe-Project header. It covers: Board ('.self::BOARD_ID.').',
            $message,
        );
    }

    public function test_header_not_covered_with_no_project_says_so(): void
    {
        $message = self::message(ProjectResolution::refused(ProjectRefusal::HeaderNotCovered, [], self::SITE_ID));

        self::assertStringEndsWith('header. It covers no project.', $message);
    }

    public function test_header_not_bound_names_the_bound_project(): void
    {
        $message = self::message(ProjectResolution::refused(
            ProjectRefusal::HeaderNotBound,
            [$this->project('Board', self::BOARD_ID)],
            self::SITE_ID,
        ));

        self::assertSame(
            'This credential is bound to one project, and the X-Loupe-Project header names another ("'.self::SITE_ID.'"). Remove the header, or set it to Board ('.self::BOARD_ID.').',
            $message,
        );
    }

    public function test_header_malformed_names_loupe_init_and_an_example_id(): void
    {
        self::assertSame(
            'The project this request names is not a project id ("the-board"). Run `loupe init` in the repository to choose one. `loupe mcp --project` and the X-Loupe-Project header take an id such as 0192f3c4-5d6e-7f80-9123-456789abcdef.',
            self::message(ProjectResolution::refused(ProjectRefusal::HeaderMalformed, [], 'the-board')),
        );
    }

    public function test_the_requested_value_is_cut_to_64_characters(): void
    {
        $message = self::message(ProjectResolution::refused(ProjectRefusal::HeaderMalformed, [], str_repeat('é', 64).'TAIL'));

        self::assertStringContainsString('("'.str_repeat('é', 64).'")', $message);
        self::assertStringNotContainsString('TAIL', $message);
    }

    public function test_no_requested_value_leaves_no_quote(): void
    {
        $message = self::message(ProjectResolution::refused(ProjectRefusal::HeaderMalformed));

        self::assertStringStartsWith('The project this request names is not a project id. Run', $message);
    }

    #[DataProvider('credentialRefusals')]
    public function test_a_credential_that_reaches_no_project_asks_to_sign_in_again(ProjectRefusal $refusal): void
    {
        self::assertSame(McpRefusalMessages::NO_PROJECT_REACHED, self::message(ProjectResolution::refused($refusal)));
    }

    /** @return iterable<string, array{ProjectRefusal}> */
    public static function credentialRefusals(): iterable
    {
        yield 'unbound' => [ProjectRefusal::Unbound];
        yield 'no credential' => [ProjectRefusal::NoCredential];
        yield 'wrong scope' => [ProjectRefusal::WrongScope];
    }

    private static function message(ProjectResolution $resolution): string
    {
        $subject = new class {
            use ResolvesBoundProject;

            public function of(ProjectResolution $resolution): string
            {
                return self::refusalMessage($resolution);
            }
        };

        return $subject->of($resolution);
    }

    private function project(string $name, string $id): Project
    {
        $project = new Project(new User(fullName: 'U', email: 'u@example.com', password: 'x'), $name);
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::fromString($id));

        return $project;
    }
}
