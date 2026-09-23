<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Security\WorkerRunTopicAuthorizer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class WorkerRunTopicAuthorizerTest extends KernelTestCase
{
    use BridgeScenario;

    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->owner = $this->user($this->em(), 'run-topic-owner@example.com');
        $this->project = $this->project($this->em(), $this->owner, 'Run topic');
    }

    public function test_a_viewer_of_the_project_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->runTopic($this->project)));
    }

    public function test_a_stranger_is_refused(): void
    {
        $stranger = $this->user($this->em(), 'run-topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->runTopic($this->project)));
        // Guard: the stranger passes on a project of their own, so the refusal is about the project.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->runTopic($this->project($this->em(), $stranger, 'Theirs'))));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->runTopic($this->project)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forWorkerRuns(Uuid::v7())));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->project->id ?? throw new \LogicException('The project has no id.');

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forProject($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forWorkerRuns($projectId).'/extra'));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function runTopic(Project $project): string
    {
        return $this->topics()->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'));
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function authorizer(): WorkerRunTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(WorkerRunTopicAuthorizer::class);
        self::assertInstanceOf(WorkerRunTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
