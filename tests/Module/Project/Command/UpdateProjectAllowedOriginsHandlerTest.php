<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\UpdateProjectAllowedOriginsCommand;
use App\Module\Project\Command\UpdateProjectAllowedOriginsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\SiteOrigins;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\AuditOutcome;

final class UpdateProjectAllowedOriginsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UpdateProjectAllowedOriginsHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new UpdateProjectAllowedOriginsHandler($this->em, $this->audit->auditor);
    }

    public function test_it_stores_one_normalised_origin_per_line_without_duplicates(): void
    {
        $project = $this->project('origins-a@example.com');

        ($this->handler)(new UpdateProjectAllowedOriginsCommand($project, "https://Shop.Example.com/\r\n\n  http://localhost:3000\nhttps://shop.example.com:443\n"));

        $projectId = $project->id;
        $this->em->clear();
        $reloaded = $this->em->find(Project::class, $projectId);
        self::assertNotNull($reloaded);
        self::assertSame(['https://shop.example.com', 'http://localhost:3000'], $reloaded->allowedOrigins);
    }

    public function test_an_empty_list_clears_the_origins(): void
    {
        $project = $this->project('origins-b@example.com');
        $project->allowedOrigins = ['https://shop.example.com'];

        ($this->handler)(new UpdateProjectAllowedOriginsCommand($project, "  \n"));

        self::assertSame([], $project->allowedOrigins);
    }

    public function test_one_bad_line_refuses_the_whole_list(): void
    {
        $project = $this->project('origins-c@example.com');
        $project->allowedOrigins = ['https://kept.example.com'];

        try {
            ($this->handler)(new UpdateProjectAllowedOriginsCommand($project, "https://shop.example.com\nhttps://shop.example.com/checkout"));
            self::fail('Expected DomainErrors for a line with a path.');
        } catch (DomainErrors $e) {
            self::assertSame(['origins' => 'project.allowed_origins.error.invalid'], $e->errors);
        }

        self::assertSame(['https://kept.example.com'], $project->allowedOrigins);
        self::assertSame([], $this->audit->operations());
    }

    public function test_it_refuses_more_origins_than_the_cap(): void
    {
        $project = $this->project('origins-d@example.com');
        $lines = implode("\n", array_map(static fn (int $n): string => 'https://site'.$n.'.example.com', range(1, SiteOrigins::MAX + 1)));

        $this->expectException(DomainErrors::class);
        ($this->handler)(new UpdateProjectAllowedOriginsCommand($project, $lines));
    }

    public function test_the_change_is_recorded_with_the_new_count(): void
    {
        $project = $this->project('origins-e@example.com');

        ($this->handler)(new UpdateProjectAllowedOriginsCommand($project, 'https://shop.example.com'));

        $record = $this->audit->record('project.allowed_origins_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(['projectId' => (string) $project->id, 'count' => 1], $record->context);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(UpdateProjectAllowedOriginsHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'origins-site');
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
