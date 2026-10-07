<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Mcp;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\ProjectUpdateTool;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectUpdateToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ProjectUpdateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ProjectUpdateTool::class);
        self::assertInstanceOf(ProjectUpdateTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner, string $name): Project
    {
        $project = new Project($owner, $name, 'app.example.com', description: 'What it does');
        $project->searchLanguage = SearchLanguage::French;
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function stored(Project $project): Project
    {
        $this->em->clear();
        $stored = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $stored);

        return $stored;
    }

    public function test_given_arguments_change_and_omitted_ones_keep_their_value(): void
    {
        $project = $this->project($this->user('project-update@example.test'), 'Old Name');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(name: '  New Name  ');

        self::assertSame([
            'id' => (string) $project->id,
            'slug' => 'new-name',
            'name' => 'New Name',
            'description' => 'What it does',
            'domain' => 'app.example.com',
            'searchLanguage' => 'french',
        ], $result);

        $stored = $this->stored($project);
        self::assertSame('New Name', $stored->name);
        self::assertSame('new-name', $stored->slug);
        self::assertSame('What it does', $stored->description);
        self::assertSame('app.example.com', $stored->domain);
        self::assertSame(SearchLanguage::French, $stored->searchLanguage);
    }

    public function test_the_other_settings_change_and_the_name_keeps_its_value(): void
    {
        $project = $this->project($this->user('project-update-other@example.test'), 'Kept Name');
        $this->actAsMcpTokenBoundTo($project);

        ($this->tool)(description: ' A new purpose ', domain: ' new.example.com ', searchLanguage: 'german');

        $stored = $this->stored($project);
        self::assertSame('Kept Name', $stored->name);
        self::assertSame('A new purpose', $stored->description);
        self::assertSame('new.example.com', $stored->domain);
        self::assertSame(SearchLanguage::German, $stored->searchLanguage);
    }

    public function test_an_empty_description_or_domain_clears_it(): void
    {
        $project = $this->project($this->user('project-update-clear@example.test'), 'Clear Me');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(description: '', domain: '  ');

        self::assertNull($result['description']);
        self::assertNull($result['domain']);
        $stored = $this->stored($project);
        self::assertNull($stored->description);
        self::assertNull($stored->domain);
    }

    public function test_a_rename_writes_the_outbox_event_as_the_agent(): void
    {
        $project = $this->project($this->user('project-update-outbox@example.test'), 'Before');
        $this->actAsMcpTokenBoundTo($project);

        ($this->tool)(name: 'After');

        $this->em->clear();
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);
        $rows = $outbox->findBy(['project' => $project->id, 'type' => 'project.renamed']);
        self::assertCount(1, $rows);
        $payload = json_decode($rows[0]->payload, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('agent', $payload['actor']);
        self::assertSame('before', $payload['fromSlug']);
        self::assertSame('after', $payload['toSlug']);
    }

    public function test_a_stored_description_over_the_limit_does_not_block_another_change(): void
    {
        $project = $this->project($this->user('project-update-legacy@example.test'), 'Legacy');
        $project->description = str_repeat('x', 600);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);

        ($this->tool)(searchLanguage: 'simple');

        $stored = $this->stored($project);
        self::assertSame(SearchLanguage::Simple, $stored->searchLanguage);
        self::assertSame(str_repeat('x', 600), $stored->description);
    }

    /** @return iterable<string, array{array<string, string>, non-empty-string}> */
    public static function refusals(): iterable
    {
        yield 'blank name' => [['name' => '   '], 'name: A project name must not be blank.'];
        yield 'long name' => [['name' => str_repeat('n', 101)], 'name: A project name must be at most 100 characters.'];
        yield 'long domain' => [['domain' => str_repeat('d', 256)], 'domain: A domain must be at most 255 characters.'];
        yield 'long description' => [['description' => str_repeat('x', 501)], 'description: A description must be at most 500 characters.'];
        yield 'unknown language' => [['searchLanguage' => 'klingon'], 'searchLanguage: Unknown search language "klingon". Use one of: arabic,'];
        yield 'no letter or digit' => [['name' => '!!!'], 'name: A project name needs at least one letter or digit, because the slug comes from the name.'];
    }

    /**
     * @param array<string, string> $arguments
     * @param non-empty-string      $message
     */
    #[DataProvider('refusals')]
    public function test_a_refusal_names_the_argument_and_changes_nothing(array $arguments, string $message): void
    {
        $project = $this->project($this->user('project-update-refusal-'.uniqid().'@example.test'), 'Untouched');
        $this->actAsMcpTokenBoundTo($project);

        try {
            ($this->tool)(...$arguments);
            self::fail('The tool accepted '.json_encode($arguments));
        } catch (ToolCallException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }

        $stored = $this->stored($project);
        self::assertSame('Untouched', $stored->name);
        self::assertSame('What it does', $stored->description);
        self::assertSame('app.example.com', $stored->domain);
        self::assertSame(SearchLanguage::French, $stored->searchLanguage);
    }

    public function test_a_name_another_project_of_the_owner_holds_is_refused(): void
    {
        $owner = $this->user('project-update-taken@example.test');
        $this->project($owner, 'Taken');
        $project = $this->project($owner, 'Mine');
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('name: Another project of this owner already has this name. Choose another name.');
        ($this->tool)(name: 'Taken');
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('project-update-unbound@example.test');
        $this->em->flush();
        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)(name: 'Anything');
    }
}
