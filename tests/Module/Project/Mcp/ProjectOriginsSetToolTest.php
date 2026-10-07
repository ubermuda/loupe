<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\ProjectOriginsSetTool;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectOriginsSetToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ProjectOriginsSetTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ProjectOriginsSetTool::class);
        self::assertInstanceOf(ProjectOriginsSetTool::class, $tool);
        $this->tool = $tool;
    }

    private function project(string $label): Project
    {
        $owner = new User(fullName: 'U', email: $label.'@example.test', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $label);
        $project->allowedOrigins = ['https://old.example.com'];
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /** @return list<string> */
    private function stored(Project $project): array
    {
        $this->em->clear();
        $stored = $this->em->find(Project::class, $project->id);
        self::assertInstanceOf(Project::class, $stored);

        return $stored->allowedOrigins;
    }

    public function test_the_list_replaces_the_stored_one_in_its_normal_form(): void
    {
        $project = $this->project('origins-set');
        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)(['https://Staging.Example.com/', 'https://*.example.com', 'http://localhost:8000', 'https://staging.example.com']);

        $expected = ['https://staging.example.com', 'https://*.example.com', 'http://localhost:8000'];
        self::assertSame(['origins' => $expected], $result);
        self::assertSame($expected, $this->stored($project));
    }

    public function test_an_empty_list_clears_the_origins(): void
    {
        $project = $this->project('origins-clear');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['origins' => []], ($this->tool)([]));
        self::assertSame([], $this->stored($project));
    }

    /** @return iterable<string, array{list<mixed>, non-empty-string}> */
    public static function refusals(): iterable
    {
        yield 'invalid origin' => [['https://example.com/path'], 'origins: Each entry must be a site origin with no path'];
        yield 'plain http off localhost' => [['http://example.com'], 'origins: Each entry must be a site origin with no path'];
        yield 'too many' => [array_map(static fn (int $i): string => \sprintf('https://site%d.example.com', $i), range(1, 21)), 'origins: A project allows 20 origins at most.'];
        yield 'line break' => [["https://a.example.com\nhttps://b.example.com"], 'origins: An entry must not contain a line break. Pass each origin as its own entry.'];
        yield 'too long' => [[str_repeat('a', 4001)], 'origins: The origins must be at most 4000 characters together.'];
        yield 'not a string' => [[42], 'origins: Each entry must be a string.'];
    }

    /**
     * @param list<mixed>      $origins
     * @param non-empty-string $message
     */
    #[DataProvider('refusals')]
    public function test_a_refusal_names_the_argument_and_keeps_the_stored_list(array $origins, string $message): void
    {
        $project = $this->project('origins-refusal-'.uniqid());
        $this->actAsMcpTokenBoundTo($project);

        try {
            ($this->tool)($origins);
            self::fail('The tool accepted the list.');
        } catch (ToolCallException $e) {
            self::assertStringStartsWith($message, $e->getMessage());
        }

        self::assertSame(['https://old.example.com'], $this->stored($project));
    }

    public function test_an_unbound_mcp_token_is_rejected(): void
    {
        $project = $this->project('origins-unbound');
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(McpRefusalMessages::NO_PROJECT_REACHED);
        ($this->tool)([]);
    }
}
