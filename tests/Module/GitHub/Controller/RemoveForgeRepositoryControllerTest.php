<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgeRepository;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\AuditBundle\AuditOutcome;

final class RemoveForgeRepositoryControllerTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_releases_a_repository(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $owner = $this->signedUpUser('remove');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $kept = $this->repositoryOf($project, 'acme/other');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/repositories/'.$repository->id.'/remove');

        self::assertResponseRedirects('/projects/'.$project->id.'/connect#repositories');
        $this->em()->clear();
        self::assertNull($this->em()->find(ForgeRepository::class, $repository->id));
        self::assertNotNull($this->em()->find(ForgeRepository::class, $kept->id));
        self::assertSame(AuditOutcome::Success, $audit->record('github.repository_removed')->outcome);
    }

    /** A foreign id answers like an unknown one, so the route tells nobody that the row exists. */
    public function test_a_repository_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('mine');
        $project = $this->projectOf($owner);
        $otherProject = $this->projectOf($this->signedUpUser('theirs'));
        $theirs = $this->repositoryOf($otherProject, 'rival/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->postAction($client, '/projects/'.$project->id.'/repositories/'.$theirs->id.'/remove');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(ForgeRepository::class, $theirs->id));
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $repository = $this->repositoryOf($project, 'acme/app');
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->postAction($client, '/projects/'.$project->id.'/repositories/'.$repository->id.'/remove');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(ForgeRepository::class, $repository->id));
    }
}
